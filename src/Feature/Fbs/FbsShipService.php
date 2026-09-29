<?php

declare(strict_types=1);

namespace App\Feature\Fbs;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;

use function array_column;
use function array_diff;
use function array_fill;
use function array_filter;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function in_array;
use function max;
use function sprintf;

use const ARRAY_FILTER_USE_KEY;

/**
 * Assembly of FBS postings. `/v4/posting/fbs/ship` assembles the whole posting: every package becomes a posting in
 * `awaiting_deliver` — the first keeps the original number, the others get the next numbers of the order with
 * `parent_posting_number`. `/v4/posting/fbs/ship/package` assembles a part: the passed units go to a new assembled posting,
 * the rest stays unassembled under the original number; passing all units assembles the posting itself.
 *
 * Exemplars follow their units: explicit `exemplarsIds`, otherwise the confirmed exemplars in order. Reserved stock stays
 * reserved. A scenario `shipFailures` accepts the request and leaves the posting in `awaiting_packaging` with substatus
 * `ship_failed`, as Ozon reports a failed assembly through `/v3/posting/fbs/get`.
 */
final readonly class FbsShipService
{
    public const array PATHS = ['/v4/posting/fbs/ship', '/v4/posting/fbs/ship/package', '/v1/posting/fbs/split'];

    public const array WRITE_PATHS = self::PATHS;

    public function __construct(
        private FbsExemplarService $exemplars,
    ) {
    }

    public function supports(string $path): bool
    {
        return in_array($path, self::PATHS, true);
    }

    public function handle(CabinetState $state, string $path, array $input, int $now): array
    {
        $number = (string) $input['posting_number'];
        FbsExemplarService::posting($state, $number);
        $this->exemplars->settle($state, $number, $now);
        $posting = $state->data['fbs']['postings'][$number];
        if ($path === '/v1/posting/fbs/split') {
            return $this->splitPosting($state, $posting, $input['postings'] ?? [], $now);
        }
        if ($posting['status'] !== 'awaiting_packaging' || array_filter($posting['available_actions'], static fn (string $a): bool => in_array($a, ['ship', 'ship_with_additional_info'], true)) === []) {
            throw new SellerApiException('Posting ' . $number . ' cannot be assembled in status ' . $posting['status'], 400, 3);
        }

        return $path === '/v4/posting/fbs/ship' ? $this->ship($state, $posting, $input, $now) : $this->shipPackage($state, $posting, $input['products'] ?? [], $now);
    }

    private function ship(CabinetState $state, array $posting, array $input, int $now): array
    {
        $lines    = array_column($posting['products'], 'quantity', 'sku');
        $packages = [];
        $total    = [];
        foreach ($input['packages'] as $package) {
            $units = [];
            foreach ($package['products'] as $product) {
                $sku      = (int) $product['product_id'];
                $quantity = (int) $product['quantity'];
                if (!isset($lines[$sku]) || $quantity < 1) {
                    throw new SellerApiException(sprintf('Invalid product %d or quantity for posting %s', $sku, $posting['posting_number']), 400, 3);
                }
                $units[$sku] = ($units[$sku] ?? 0) + $quantity;
                $total[$sku] = ($total[$sku] ?? 0) + $quantity;
            }
            $packages[] = array_map(static fn (int $quantity): array => ['quantity' => $quantity, 'ids' => null], $units);
        }
        if ($packages === [] || $total != $lines) {
            throw new SellerApiException('Packages must contain exactly the products of posting ' . $posting['posting_number'], 400, 3);
        }
        $this->ready($posting, array_keys($lines));
        if ($this->failed($state, $posting['posting_number'], $now)) {
            return ['result' => [$posting['posting_number']]];
        }
        $numbers = $this->split($state, $posting, $packages, array_fill(0, count($packages), true), true, $now);

        return ['result' => $numbers, ...(($input['with']['additional_data'] ?? false) === true ? ['additional_data' => array_map(fn (string $n): array => $this->additional($state, $n), $numbers)] : [])];
    }

    private function shipPackage(CabinetState $state, array $posting, array $products, int $now): array
    {
        $lines   = array_column($posting['products'], 'quantity', 'sku');
        $package = [];
        foreach ($products as $product) {
            $sku      = (int) $product['product_id'];
            $quantity = (int) $product['quantity'];
            $ids      = array_map('intval', $product['exemplarsIds'] ?? []);
            $known    = array_column($posting['exemplars'][$sku] ?? [], 'exemplar_id');
            if (!isset($lines[$sku]) || isset($package[$sku]) || $quantity < 1 || $quantity > $lines[$sku]
                || ($ids !== [] && (count($ids) !== $quantity || count(array_unique($ids)) !== $quantity || array_diff($ids, $known) !== []))) {
                throw new SellerApiException(sprintf('Invalid product %d, quantity or exemplarsIds for posting %s', $sku, $posting['posting_number']), 400, 3);
            }
            $package[$sku] = ['quantity' => $quantity, 'ids' => $ids === [] ? null : $ids];
        }
        if ($package === []) {
            throw new SellerApiException('products required', 400, 3);
        }
        $this->ready($posting, array_keys($package));
        if ($this->failed($state, $posting['posting_number'], $now)) {
            return ['result' => $posting['posting_number']];
        }
        if (array_map(static fn (array $units): int => $units['quantity'], $package) == $lines) {
            return ['result' => $this->split($state, $posting, [$package], [true], true, $now)[0]];
        }
        $rest = [];
        foreach ($lines as $sku => $quantity) {
            $left = $quantity - ($package[$sku]['quantity'] ?? 0);
            if ($left > 0) {
                $rest[$sku] = ['quantity' => $left, 'ids' => null];
            }
        }
        // The passed part becomes a new assembled posting; the original number keeps the rest unassembled.
        [, $part] = $this->split($state, $posting, [$rest, $package], [false, true], true, $now);

        return ['result' => $part];
    }

    /**
     * `/v1/posting/fbs/split` divides an unassembled posting into new postings without assembling them. The emulator model
     * (Ozon does not describe the numbers): the original posting becomes `cancelled_from_split_pending` and keeps its
     * products for history; every part gets a new number of the order with `parent_posting_number`; the parts together
     * hold exactly the products of the original. Exemplars follow the units in order, the stock stays reserved.
     */
    private function splitPosting(CabinetState $state, array $posting, array $postings, int $now): array
    {
        if ($posting['status'] !== 'awaiting_packaging') {
            throw new SellerApiException('Posting ' . $posting['posting_number'] . ' cannot be split in status ' . $posting['status'], 400, 3);
        }
        $lines = array_column($posting['products'], 'quantity', 'sku');
        $parts = [];
        $total = [];
        foreach ($postings as $part) {
            $units = [];
            foreach ($part['products'] ?? [] as $product) {
                $sku      = (int) $product['product_id'];
                $quantity = (int) $product['quantity'];
                if (!isset($lines[$sku]) || $quantity < 1) {
                    throw new SellerApiException(sprintf('Invalid product %d or quantity for posting %s', $sku, $posting['posting_number']), 400, 3);
                }
                $units[$sku] = ($units[$sku] ?? 0) + $quantity;
                $total[$sku] = ($total[$sku] ?? 0) + $quantity;
            }
            if ($units === []) {
                throw new SellerApiException('Every posting of the split needs products', 400, 3);
            }
            $parts[] = array_map(static fn (int $quantity): array => ['quantity' => $quantity, 'ids' => null], $units);
        }
        if (count($parts) < 2 || $total != $lines) {
            throw new SellerApiException('Split into two or more postings with exactly the products of posting ' . $posting['posting_number'], 400, 3);
        }
        $numbers                     = $this->split($state, $posting, $parts, array_fill(0, count($parts), false), false, $now);
        $parent                      = &$state->data['fbs']['postings'][$posting['posting_number']];
        $parent['status']            = 'cancelled_from_split_pending';
        $parent['substatus']         = 'posting_canceled';
        $parent['available_actions'] = [];
        unset($parent);
        $products = static fn (array $record): array => array_map(static fn (array $line): array => ['product_id' => $line['sku'], 'quantity' => $line['quantity']], $record['products']);

        return ['parent_posting' => ['posting_number' => $posting['posting_number'], 'products' => $products($posting)],
            'postings'           => array_map(fn (string $n): array => ['posting_number' => $n, 'products' => $products($state->data['fbs']['postings'][$n])], $numbers)];
    }

    /** Units of the given SKUs must have the country and validated exemplars, like `ship_with_additional_info` requires. */
    private function ready(array $posting, array $skus): void
    {
        $scoped                 = $posting;
        $scoped['products']     = array_values(array_filter($posting['products'], static fn (array $line): bool => in_array($line['sku'], $skus, true)));
        $requirements           = FbsRequirements::of($posting);
        $scoped['requirements'] = array_map(static fn (array $list): array => array_values(array_filter($list, static fn (int $sku): bool => in_array($sku, $skus, true))), $requirements);
        if (!FbsExemplarService::shipReady($scoped)) {
            throw new SellerApiException('Posting ' . $posting['posting_number'] . ' needs the country of origin and validated exemplars before assembly', 400, 3);
        }
    }

    private function failed(CabinetState $state, string $number, int $now): bool
    {
        if (($state->data['fbs']['scenario']['shipFailures'] ?? 0) < 1) {
            return false;
        }
        --$state->data['fbs']['scenario']['shipFailures'];
        $state->data['fbs']['postings'][$number]['substatus'] = 'ship_failed';
        $state->event('fbs.posting.ship_failed', $now, ['posting_number' => $number]);

        return true;
    }

    /**
     * Distributes the posting into parts. With `$keepNumber` the first part keeps the original number (`ship`, `ship/package`),
     * otherwise every part gets a new number of the order (`split`); `$assembled` tells per part whether it is assembled.
     *
     * @param list<array<int, array{quantity: int, ids: ?list<int>}>> $parts
     * @param list<bool> $assembled
     *
     * @return list<string> numbers of the parts in order
     */
    private function split(CabinetState $state, array $posting, array $parts, array $assembled, bool $keepNumber, int $now): array
    {
        $number   = $posting['posting_number'];
        $lines    = array_column($posting['products'], null, 'sku');
        $pool     = $posting['exemplars'] ?? [];
        $explicit = [];
        foreach ($parts as $part) {
            foreach ($part as $sku => $units) {
                foreach ($units['ids'] ?? [] as $id) {
                    $explicit[$sku][] = $id;
                }
            }
        }
        $numbers = [];
        $index   = $this->lastIndex($state, $posting['order_number']);
        foreach ($parts as $i => $part) {
            $products  = [];
            $exemplars = [];
            foreach ($part as $sku => $units) {
                $products[] = ['quantity' => $units['quantity']] + $lines[$sku];
                $available  = array_values(array_filter($pool[$sku] ?? [], static fn (array $e): bool => in_array($e['exemplar_id'], $units['ids'] ?? [], true)
                    || ($units['ids'] === null && !in_array($e['exemplar_id'], $explicit[$sku] ?? [], true))));
                $taken      = $units['ids'] === null ? array_slice($available, 0, $units['quantity']) : $available;
                $takenIds   = array_column($taken, 'exemplar_id');
                $pool[$sku] = array_values(array_filter($pool[$sku] ?? [], static fn (array $e): bool => !in_array($e['exemplar_id'], $takenIds, true)));
                if ($taken !== []) {
                    $exemplars[$sku] = $taken;
                }
            }
            $done       = $assembled[$i];
            $original   = $i === 0 && $keepNumber;
            $partNumber = $original ? $number : sprintf('%s-%d', $posting['order_number'], ++$index);
            $skus       = array_keys($part);
            $record     = [
                'posting_number' => $partNumber, 'parent_posting_number' => $original ? ($posting['parent_posting_number'] ?? '') : $number,
                'status'         => $done ? 'awaiting_deliver' : 'awaiting_packaging', 'substatus' => $done ? 'posting_not_in_carriage' : 'posting_created',
                'multibox'       => ($posting['multibox'] ?? false) && array_intersect($skus, $posting['multibox_skus'] ?? []) !== [],
                'multibox_skus'  => array_values(array_intersect($posting['multibox_skus'] ?? [], $skus)),
                'products'       => $products, 'exemplars' => $exemplars,
                'requirements'   => array_map(static fn (array $list): array => array_values(array_filter($list, static fn (int $sku): bool => in_array($sku, $skus, true))), FbsRequirements::of($posting)),
                'countries'      => array_filter($posting['countries'] ?? [], static fn (int $sku): bool => in_array($sku, $skus, true), ARRAY_FILTER_USE_KEY),
                'multi_box_qty'  => $posting['multi_box_qty'] ?? 1,
                ...($done ? ['shipped_at' => $now] : []),
            ] + $posting;
            unset($record['exemplar_check'], $record['marked_skus']);
            $record['available_actions']                 = FbsPostingGenerator::actions($record);
            $state->data['fbs']['postings'][$partNumber] = $record;
            $numbers[]                                   = $partNumber;
        }
        $state->event('fbs.posting.shipped', $now, ['posting_number' => $number, 'result' => $numbers]);

        return $numbers;
    }

    private function lastIndex(CabinetState $state, string $orderNumber): int
    {
        $last = 1;
        foreach ($state->data['fbs']['postings'] ?? [] as $posting) {
            if ($posting['order_number'] === $orderNumber) {
                $parts = explode('-', $posting['posting_number']);
                $last  = max($last, (int) $parts[count($parts) - 1]);
            }
        }

        return $last;
    }

    private function additional(CabinetState $state, string $number): array
    {
        $posting = $state->data['fbs']['postings'][$number];

        return ['posting_number' => $number, 'products' => array_map(static fn (array $line): array => [
            'mandatory_mark' => array_values(array_map(static fn (array $m): string => $m['mark'], array_merge(...array_map(
                static fn (array $e): array => array_values(array_filter($e['marks'], static fn (array $m): bool => $m['mark_type'] === 'mandatory_mark')),
                $posting['exemplars'][$line['sku']] ?? [],
            )))),
            'name' => $line['name'], 'offer_id' => $line['offer_id'], 'price' => $line['price'], 'quantity' => $line['quantity'], 'sku' => $line['sku'], 'currency_code' => 'RUB',
        ], $posting['products'])];
    }
}
