<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\ControlCabinet;

use App\Feature\Fbo\ActService;
use App\Feature\Fbo\CabinetRepositoryInterface;
use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\CargoService;
use App\Feature\Fbo\ReadinessService;
use App\Feature\Fbo\SellerApiException;
use App\Feature\Fbo\SupplyContext;
use App\Feature\Fbs\FbsCarriageService;
use App\Feature\Fbs\FbsConfig;
use App\Feature\Fbs\FbsPostingGenerator;
use App\Feature\Fbs\FbsRequirements;
use App\Http\Request\FboControlSchema;
use Psr\Clock\ClockInterface;

use function array_column;
use function array_diff;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_search;
use function array_values;
use function gmdate;
use function hash;
use function json_encode;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/** Local operator boundary: available only in CLI, never through Seller credentials. */
final readonly class ControlCabinetHandler
{
    public function __construct(
        private CabinetRepositoryInterface $cabinets,
        private SupplyContext $context,
        private ActService $acts,
        private CargoService $cargo,
        private ClockInterface $clock,
        private ReadinessService $readiness,
        private FbsCarriageService $carriages,
    ) {
    }
    public function handle(ControlCabinetCommand $command): array
    {
        $schema = new FboControlSchema(['clientId' => $command->clientId, 'event' => $command->event]);

        $schema->validate();
        $event = $schema->getArray('event');

        return $this->cabinets->change($schema->getString('clientId'), function (CabinetState $state) use ($event): array {
            $state->config();
            $fingerprint = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR));
            if (isset($state->data['control_events'][$event['eventId']])) {
                if ($state->data['control_events'][$event['eventId']] !== $fingerprint) {
                    throw new SellerApiException('eventId already used with a different payload', 409, 10);
                }

                return ['event_id' => $event['eventId'], 'replayed' => true];
            }
            $now = $this->clock->now()->getTimestamp() + $state->config()['clockOffsetSeconds'];
            switch ($event['type']) {
                case 'scenario':
                    $state->data['scenario'] = $event + ['remaining' => 1];
                    break;
                case 'beta':
                    $state->data['disabled_paths'] = $event['disabledPaths'];
                    break;
                case 'advance':
                    $state->data['config']['clockOffsetSeconds'] += $event['seconds'];
                    break;
                case 'release':
                    $id = $event['operationId'];
                    if (!isset($state->data['operations'][$id])) {
                        throw new SellerApiException('Operation not found', 404, 5);
                    }
                    $state->data['operations'][$id]['behavior']['hold'] = false;
                    $state->data['operations'][$id]['ready_at']         = $now;
                    break;
                case 'state':
                    $this->state($state, $event, $now);
                    break;
                case 'acceptance':
                    $this->acts->receive($state, $event['supplyId'], $event['items'], $now);
                    break;
                case 'orderTags':
                    // A virtual distribution centre order: details report it and forbid slot, cancellation and content changes.
                    $this->context->order($state, $event['orderId']);
                    $state->data['orders'][$event['orderId']]['order_tags'] = ['is_virtual' => $event['isVirtual']];
                    $this->context->touch($state, $event['orderId'], $now);
                    break;
                case 'requirements':
                    [$orderId] = $this->context->locate($state, $event['supplyId']);
                    $this->context->editable($this->context->order($state, (int) $orderId));
                    $state->data['requirements'][$event['supplyId']] = $event;
                    $this->context->touch($state, (int) $orderId, $now);
                    break;
                case 'fbsScenario':
                    FbsConfig::of($state);
                    $state->data['fbs']['scenario'] = array_intersect_key($event, array_flip(['shipFailures', 'labelFailures', 'rejectedMarks', 'carriagePassRequired'])) + ($state->data['fbs']['scenario'] ?? []);
                    break;
                case 'fbsRequirements':
                    $this->fbsRequirements($state, $event['postingNumber'], $event['requirements']);
                    break;
                case 'fbsMarkChange':
                    $this->fbsMarkChange($state, $event['postingNumber'], $event['exemplarId'], $event['mark'], $now);
                    break;
                case 'fbsHandover':
                    FbsConfig::of($state);
                    $this->carriages->handover($state, $event['carriageId'], $event['missingPostings'] ?? [], $now);
                    break;
                case 'reset':
                    $state->data = new CabinetState(['config' => $state->data['config'], 'config_version' => $state->data['config_version'], 'config_history' => $state->data['config_history'] ?? [], 'sequence' => $state->data['sequence']])->data;
                    break;
            }
            $state->data['control_events'][$event['eventId']] = $fingerprint;
            $state->event('control.' . $event['type'], $now, array_intersect_key($event, array_flip(['eventId', 'orderId', 'supplyId', 'state', 'operationId', 'postingNumber', 'carriageId'])));

            return ['event_id' => $event['eventId'], 'replayed' => false];
        });
    }

    /**
     * The seller changes the KIZ of an exemplar of an assembled posting in the cabinet, outside the Seller API of the
     * warehouse: the posting then holds another mandatory mark than the one the warehouse sent.
     */
    private function fbsMarkChange(CabinetState $state, string $number, int $exemplarId, string $mark, int $now): void
    {
        FbsConfig::of($state);
        $posting = $state->data['fbs']['postings'][$number] ?? throw new SellerApiException('Posting not found', 404, 5);
        if ($posting['status'] !== 'awaiting_deliver') {
            throw new SellerApiException('A mark changes only in an assembled posting before delivery', 409, 10);
        }
        foreach ($posting['exemplars'] ?? [] as $sku => $exemplars) {
            foreach ($exemplars as $index => $exemplar) {
                if ((int) $exemplar['exemplar_id'] !== $exemplarId) {
                    continue;
                }
                $marks                                       = array_values(array_filter($exemplar['marks'] ?? [], static fn (array $m): bool => $m['mark_type'] !== 'mandatory_mark'));
                $posting['exemplars'][$sku][$index]['marks'] = [...$marks, ['mark' => $mark, 'mark_type' => 'mandatory_mark']];
                $state->data['fbs']['postings'][$number]     = $posting;
                $state->event('fbs.posting.mark_changed', $now, ['posting_number' => $number, 'exemplar_id' => $exemplarId]);

                return;
            }
        }

        throw new SellerApiException('Exemplar ' . $exemplarId . ' is not in posting ' . $number, 404, 5);
    }

    /** Changes requirements of an unassembled posting, like Ozon may change them after the posting appears; only its SKUs. */
    private function fbsRequirements(CabinetState $state, string $number, array $requirements): void
    {
        FbsConfig::of($state);
        $posting = $state->data['fbs']['postings'][$number] ?? throw new SellerApiException('Posting not found', 404, 5);
        if ($posting['status'] !== 'awaiting_packaging') {
            throw new SellerApiException('Requirements can change only before assembly', 409, 10);
        }
        $skus = array_column($posting['products'], 'sku');
        foreach ($requirements as $list) {
            if (array_diff($list, $skus) !== []) {
                throw new SellerApiException('Requirement SKU is not in posting ' . $number, 400, 3);
            }
        }
        $posting['requirements']                 = $requirements + FbsRequirements::of($posting);
        $posting['available_actions']            = FbsPostingGenerator::actions($posting);
        $state->data['fbs']['postings'][$number] = $posting;
    }

    private function state(CabinetState $state, array $event, int $now): void
    {
        $id       = $event['orderId'];
        $order    = $this->context->order($state, $id);
        $next     = $event['state'];
        $progress = ['DATA_FILLING', 'READY_TO_SUPPLY', 'ACCEPTED_AT_SUPPLY_WAREHOUSE', 'IN_TRANSIT', 'ACCEPTANCE_AT_STORAGE_WAREHOUSE', 'REPORTS_CONFIRMATION_AWAITING', 'COMPLETED'];
        $oldRank  = array_search($order['state'], $progress, true);
        $newRank  = array_search($next, $progress, true);
        if ($oldRank === false || ($newRank !== false && $newRank < $oldRank) || ($next === 'CANCELLED' && $oldRank >= 2)) {
            throw new SellerApiException('External state cannot roll back physical facts or revive a terminal order', 409, 10);
        }
        if ($newRank !== false && $newRank >= 1 && $oldRank < 2) {
            if (!$this->readiness->ready($state, $order, $now)) {
                throw new SellerApiException('Cargo, document or vehicle requirements do not allow handover', 409, 10);
            }
        }
        $order['state'] = $next;
        foreach ($order['supplies'] as &$supply) {
            $supply['state'] = $next;
            if ($next === 'OVERDUE') {
                // Ozon explains the overdue per supply in /v1/supply-order/details; the reasons enum is not documented.
                $supply['overdue_reason'] = $event['overdueReason'] ?? 'UNSPECIFIED';
            }
            if ($newRank !== false && $newRank >= 2 && isset($state->data['cargo'][$supply['supply_id']])) {
                $state->data['cargo'][$supply['supply_id']]['tracking_status'] = $newRank >= 5 ? 'PROCESSED' : ($newRank >= 4 ? 'ACCEPTING' : 'ON_WAREHOUSE');
                $state->data['cargo'][$supply['supply_id']]['arrival_at']      = gmdate(DATE_ATOM, $now);
            }
        }
        unset($supply);
        $state->data['orders'][$id] = $order;
        $this->context->touch($state, $id, $now);
    }
}
