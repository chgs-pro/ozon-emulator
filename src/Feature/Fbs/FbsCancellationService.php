<?php

declare(strict_types=1);

namespace App\Feature\Fbs;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;

use function array_column;
use function array_filter;
use function array_values;
use function in_array;
use function trim;

/**
 * Cancellation of FBS postings by the seller: reason dictionaries, the whole posting (`/v2/posting/fbs/cancel`) and some of
 * its products (`/v2/posting/fbs/product/cancel`). Cancelled units return from reserved to free stock.
 */
final readonly class FbsCancellationService
{
    public const array PATHS = ['/v1/posting/fbs/cancel-reason', '/v2/posting/fbs/cancel-reason/list', '/v2/posting/fbs/cancel', '/v2/posting/fbs/product/cancel'];

    public const array WRITE_PATHS = ['/v2/posting/fbs/cancel', '/v2/posting/fbs/product/cancel'];

    /** «Другое (вина продавца)»: the message is required. */
    private const int REASON_OTHER = 402;

    /**
     * Reason ids and titles from the Seller API description of `cancel_reason_id`; the initiator of 665–667 and the
     * availability flags are chosen by the emulator — the snapshot does not give them.
     */
    private const array REASONS = [
        ['id' => 352, 'title' => 'Товар закончился на складе продавца', 'type_id' => 'seller', 'is_available_for_cancellation' => true],
        ['id' => 400, 'title' => 'Остался только бракованный товар', 'type_id' => 'seller', 'is_available_for_cancellation' => true],
        ['id' => 401, 'title' => 'Продавец отклонил арбитраж', 'type_id' => 'seller', 'is_available_for_cancellation' => false],
        ['id' => 402, 'title' => 'Другое (вина продавца)', 'type_id' => 'seller', 'is_available_for_cancellation' => true],
        ['id' => 665, 'title' => 'Покупатель не забрал заказ', 'type_id' => 'buyer', 'is_available_for_cancellation' => false],
        ['id' => 666, 'title' => 'Возврат из службы доставки: нет доставки в указанный регион', 'type_id' => 'buyer', 'is_available_for_cancellation' => false],
        ['id' => 667, 'title' => 'Заказ утерян службой доставки', 'type_id' => 'buyer', 'is_available_for_cancellation' => false],
    ];

    /** Seller cancellations are possible until the posting is assembled. */
    private const array CANCELLABLE = ['awaiting_registration', 'awaiting_packaging'];

    public function supports(string $path): bool
    {
        return in_array($path, self::PATHS, true);
    }

    public function handle(CabinetState $state, string $path, array $input, int $now): array
    {
        return match ($path) {
            '/v1/posting/fbs/cancel-reason'      => ['result' => $this->postingReasons($state, $input['related_posting_numbers'])],
            '/v2/posting/fbs/cancel-reason/list' => ['result' => self::REASONS],
            '/v2/posting/fbs/cancel'             => $this->cancel($state, $input, $now),
            default                              => $this->cancelProducts($state, $input, $now),
        };
    }

    /** @param list<string> $numbers */
    private function postingReasons(CabinetState $state, array $numbers): array
    {
        $result = [];
        foreach ($numbers as $number) {
            $posting = $state->data['fbs']['postings'][(string) $number] ?? null;
            if ($posting === null) {
                continue;
            }
            $reasons = [];
            foreach (self::REASONS as $reason) {
                if ($reason['type_id'] === 'seller' && $reason['is_available_for_cancellation'] && in_array($posting['status'], self::CANCELLABLE, true)) {
                    $reasons[] = ['id' => $reason['id'], 'title' => $reason['title'], 'type_id' => $reason['type_id']];
                }
            }
            $result[] = ['posting_number' => (string) $number, 'reasons' => $reasons];
        }

        return $result;
    }

    private function cancel(CabinetState $state, array $input, int $now): array
    {
        $number  = (string) $input['posting_number'];
        $posting = $this->cancellable($state, $number, 'cancel');
        $reason  = $this->reason((int) $input['cancel_reason_id'], trim((string) ($input['cancel_reason_message'] ?? '')));
        foreach ($posting['products'] as $line) {
            FbsStockService::release($state, $line['sku'], $posting['warehouse_id'], $line['quantity']);
        }
        $this->markCancelled($state, $number, $reason, $now);

        return ['result' => true];
    }

    private function cancelProducts(CabinetState $state, array $input, int $now): array
    {
        $number  = (string) $input['posting_number'];
        $posting = $this->cancellable($state, $number, 'product_cancel');
        $reason  = $this->reason((int) $input['cancel_reason_id'], trim((string) $input['cancel_reason_message']), true);
        $lines   = array_column($posting['products'], null, 'sku');
        foreach ($input['items'] as $item) {
            $sku      = (int) $item['sku'];
            $quantity = (int) $item['quantity'];
            if (!isset($lines[$sku]) || $quantity < 1 || $quantity > $lines[$sku]['quantity']) {
                throw new SellerApiException('Invalid product or quantity for posting ' . $number, 400, 3);
            }
            $lines[$sku]['quantity'] -= $quantity;
            FbsStockService::release($state, $sku, $posting['warehouse_id'], $quantity);
        }
        $left                                                = array_values(array_filter($lines, static fn (array $line): bool => $line['quantity'] > 0));
        $state->data['fbs']['postings'][$number]['products'] = $left;
        if ($left === []) {
            $this->markCancelled($state, $number, $reason, $now);
        } else {
            $state->data['fbs']['postings'][$number]['available_actions'] = FbsPostingGenerator::actions($state->data['fbs']['postings'][$number]);
            $state->event('fbs.posting.products_cancelled', $now, ['posting_number' => $number, 'items' => $input['items']]);
        }

        return ['result' => $number];
    }

    private function cancellable(CabinetState $state, string $number, string $action): array
    {
        $posting = $state->data['fbs']['postings'][$number] ?? throw new SellerApiException('Posting not found', 404, 5);
        if (!in_array($posting['status'], self::CANCELLABLE, true) || !in_array($action, $posting['available_actions'], true)) {
            throw new SellerApiException('Posting ' . $number . ' cannot be cancelled in status ' . $posting['status'], 400, 3);
        }

        return $posting;
    }

    /** @return array{id: int, title: string, message: string} */
    private function reason(int $id, string $message, bool $partial = false): array
    {
        foreach (self::REASONS as $reason) {
            if ($reason['id'] === $id && $reason['type_id'] === 'seller' && $reason['is_available_for_cancellation']) {
                if (($partial || $id === self::REASON_OTHER) && $message === '') {
                    throw new SellerApiException('cancel_reason_message is required', 400, 3);
                }

                return ['id' => $id, 'title' => $reason['title'], 'message' => $message];
            }
        }

        throw new SellerApiException('Unknown or unavailable cancel_reason_id ' . $id, 400, 3);
    }

    /** @param array{id: int, title: string, message: string} $reason */
    private function markCancelled(CabinetState $state, string $number, array $reason, int $now): void
    {
        $posting                      = &$state->data['fbs']['postings'][$number];
        $posting['status']            = 'cancelled';
        $posting['substatus']         = 'posting_canceled';
        $posting['available_actions'] = [];
        $posting['cancellation']      = [
            'cancel_reason_id'           => $reason['id'],
            'cancel_reason'              => $reason['title'],
            'cancellation_type'          => 'seller',
            'cancellation_initiator'     => 'Продавец',
            'cancelled_after_ship'       => false,
            'affect_cancellation_rating' => true,
        ];
        unset($posting);
        $state->event('fbs.posting.cancelled', $now, ['posting_number' => $number, 'cancel_reason_id' => $reason['id']]);
    }
}
