<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\AdvanceSupply;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\DraftPlanner;
use DateTimeImmutable;
use DateTimeZone;

use function gmdate;
use function in_array;

use const DATE_ATOM;

final readonly class AdvanceSupplyHandler
{
    public function __construct(
        private DraftPlanner
    $planner,
    ) {
    }
    public function handle(CabinetState $state, AdvanceSupplyCommand $command): void
    {
        // FBO timeline only; an FBS-only cabinet has nothing to advance here.
        if (!$state->config()['fboEnabled']) {
            return;
        }
        foreach ($state->data['drafts'] as $draft) {
            $op = $draft['supply_operation'];
            if ($op === null || $op['failed'] || $op['ready_at'] > $command->now || isset($state->data['orders'][(string) $op['order_id']])) {
                continue;
            }
            $zone = new DateTimeZone($op['warehouse']['timezone']);

            $from     = new DateTimeImmutable($op['slot']['from_in_timezone'], $zone);
            $to       = new DateTimeImmutable($op['slot']['to_in_timezone'], $zone);
            $supplies = [];
            foreach ($op['selected'] as $clusterId => $option) {
                $tags = [];
                foreach (['ETTN_REQUIRED' => 'is_ettn_required', 'EVSD_REQUIRED' => 'is_evsd_required', 'JEWELRY' => 'is_jewelry', 'MARKING_REQUIRED' => 'is_marking_required', 'MARKING_POSSIBLE' => 'is_marking_possible', 'UTD_REQUIRED' => 'is_utd'] as $tag => $field) {
                    $tags[$field] = in_array($tag, $option['supply_tags'], true);
                }
                $supplies[] = ['supply_id' => $state->id(), 'bundle_id' => $option['bundle_id'], 'macrolocal_cluster_id' => (int) $clusterId, 'storage_warehouse' => $option['storage_warehouse'], 'is_crossdock' => $draft['type'] !== 'DIRECT', 'state' => 'DATA_FILLING', 'supply_tags' => $tags];
            }
            $state->data['orders'][(string) $op['order_id']] = ['order_id' => $op['order_id'], 'order_number' => 'TEST-' . $op['order_id'], 'created_date' => gmdate(DATE_ATOM, $op['created_at']), 'data_filling_deadline_utc' => gmdate(DATE_ATOM, $from->getTimestamp() - 3600), 'state_updated_date' => gmdate(DATE_ATOM, $op['ready_at']), 'state' => 'DATA_FILLING', 'dropoff_warehouse' => $this->planner->warehouse($op['warehouse']), 'supplies' => $supplies, 'timeslot' => ['timeslot' => ['from' => $from->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM), 'to' => $to->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM)], 'timezone_info' => ['iana_name' => $zone->getName(), 'offset' => $from->format('P')]]];
            $state->event('supply.success', $command->now, ['draft_id' => $draft['id'], 'order_id' => $op['order_id']]);
        }
    }
}
