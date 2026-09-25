<?php

declare(strict_types=1);

namespace App\Feature\Fbs;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;

use function array_column;
use function array_count_values;
use function array_filter;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function mb_stripos;
use function preg_match;
use function round;
use function sprintf;
use function str_contains;

/**
 * Exemplars of posting products and the country of origin.
 *
 * `set` stores the full submitted set as a check; the check completes `operationDelaySeconds` later (cabinet clock) and is
 * evaluated on the next request. The submitted data never replaces the confirmed exemplars before the check passes: before
 * assembly a passed check is saved at once, after assembly (`awaiting_deliver`) — only by `/v1/fbs/posting/product/exemplar/update`.
 */
final readonly class FbsExemplarService
{
    public const array PATHS = [
        '/v6/fbs/posting/product/exemplar/create-or-get', '/v5/fbs/posting/product/exemplar/validate', '/v6/fbs/posting/product/exemplar/set',
        '/v5/fbs/posting/product/exemplar/status', '/v1/fbs/posting/product/exemplar/update', '/v2/posting/fbs/product/country/list',
        '/v2/posting/fbs/product/country/set',
    ];

    public const array WRITE_PATHS = ['/v6/fbs/posting/product/exemplar/set', '/v1/fbs/posting/product/exemplar/update', '/v2/posting/fbs/product/country/set'];

    /** Countries of origin of the emulator; a country other than Russia makes the product need a customs declaration. */
    public const array COUNTRIES = [
        ['name' => 'Россия', 'country_iso_code' => 'RU'], ['name' => 'Беларусь', 'country_iso_code' => 'BY'], ['name' => 'Казахстан', 'country_iso_code' => 'KZ'],
        ['name' => 'Китай', 'country_iso_code' => 'CN'], ['name'  => 'Турция', 'country_iso_code' => 'TR'], ['name' => 'Узбекистан', 'country_iso_code' => 'UZ'],
    ];

    /** Statuses in which exemplars can be submitted: before assembly and, for the update flow, after it. */
    private const array EDITABLE = ['awaiting_packaging', 'awaiting_deliver'];

    private const array MARK_TYPES = ['mandatory_mark', 'imei', 'jw_uin'];

    public function supports(string $path): bool
    {
        return in_array($path, self::PATHS, true);
    }

    public function handle(CabinetState $state, string $path, array $input, int $now): array
    {
        if ($path === '/v2/posting/fbs/product/country/list') {
            $search = (string) ($input['name_search'] ?? '');

            return ['result' => array_values(array_filter(self::COUNTRIES, static fn (array $c): bool => $search === '' || mb_stripos($c['name'], $search) !== false))];
        }
        $number = (string) $input['posting_number'];
        self::posting($state, $number);
        $this->settle($state, $number, $now);

        return match ($path) {
            '/v6/fbs/posting/product/exemplar/create-or-get' => $this->createOrGet($state, $number),
            '/v5/fbs/posting/product/exemplar/validate'      => $this->validate($state, $number, $input['products']),
            '/v6/fbs/posting/product/exemplar/set'           => $this->set($state, $number, $input, $now),
            '/v5/fbs/posting/product/exemplar/status'        => $this->status($state, $number),
            '/v1/fbs/posting/product/exemplar/update'        => $this->update($state, $number, $now),
            default                                          => $this->country($state, $number, $input, $now),
        };
    }

    public static function posting(CabinetState $state, string $number): array
    {
        return $state->data['fbs']['postings'][$number] ?? throw new SellerApiException('Posting not found', 404, 5);
    }

    /**
     * Whether the confirmed exemplars of every unit that needs metadata carry it; the country of origin must be set too.
     * Blank exemplars of create-or-get do not count. Used by assembly: `ship` of a posting that is not ready is rejected.
     */
    public static function shipReady(array $posting): bool
    {
        $requirements = FbsRequirements::of($posting);
        if ($requirements['country'] !== []) {
            return false;
        }
        foreach ($posting['products'] as $line) {
            $needs     = array_filter(FbsRequirements::EXEMPLAR_KINDS, static fn (string $kind): bool => in_array($line['sku'], $requirements[$kind], true));
            $exemplars = $posting['exemplars'][$line['sku']] ?? [];
            if ($needs !== [] && (count($exemplars) !== $line['quantity'] || array_filter($exemplars, static fn (array $e): bool => self::missing($posting, $line['sku'], $e) !== null) !== [])) {
                return false;
            }
        }

        return true;
    }

    /** Completes a due check: results are frozen; a passed check before assembly becomes the confirmed exemplars. */
    public function settle(CabinetState $state, string $number, int $now): void
    {
        $posting = &$state->data['fbs']['postings'][$number];
        $check   = $posting['exemplar_check'] ?? null;
        if ($check === null || $check['evaluated'] || $now < $check['ready_at']) {
            unset($posting);

            return;
        }
        $rejected = $state->data['fbs']['scenario']['rejectedMarks'] ?? [];
        $foreign  = $this->foreignMarks($state, $number);
        $counts   = array_count_values(array_map(static fn (array $m): string => $m['mark'], $this->marks($check['products'])));
        $passed   = true;
        foreach ($check['products'] as &$product) {
            $sku = $product['product_id'];
            foreach ($product['exemplars'] as &$exemplar) {
                foreach ($exemplar['marks'] as &$mark) {
                    $errors = match (true) {
                        ($format = $this->markError($mark['mark_type'], $mark['mark'])) !== null => [$format],
                        $counts[$mark['mark']] > 1 || in_array($mark['mark'], $foreign, true)    => ['DUPLICATE_MARK'],
                        in_array($mark['mark'], $rejected, true)                                 => ['NOT_IN_TURNOVER'],
                        default                                                                  => [],
                    };
                    $mark += ['check_status' => $errors === [] ? 'passed' : 'failed', 'error_codes' => $errors];
                    $passed = $passed && $errors === [];
                }
                unset($mark);
                foreach (['gtd' => $this->gtdError(...), 'rnpt' => $this->rnptError(...)] as $field => $validator) {
                    $error                              = $exemplar[$field] === '' ? null : $validator($exemplar[$field]);
                    $exemplar[$field . '_check_status'] = $exemplar[$field] === '' ? '' : ($error === null ? 'passed' : 'failed');
                    $exemplar[$field . '_error_codes']  = $error === null ? [] : [$error];
                    $passed                             = $passed && $error === null;
                }
                [$min, $max]                     = self::weightRange($state, $sku);
                $weightError                     = FbsRequirements::needs($posting, 'weight', $sku) && ($exemplar['weight'] < $min || $exemplar['weight'] > $max) ? 'WEIGHT_OUT_OF_RANGE' : null;
                $exemplar['weight_check_status'] = FbsRequirements::needs($posting, 'weight', $sku) ? ($weightError === null ? 'passed' : 'failed') : '';
                $exemplar['weight_error_codes']  = $weightError === null ? [] : [$weightError];
                $passed                          = $passed && $weightError === null;
            }
            unset($exemplar);
        }
        unset($product);
        $check['evaluated'] = true;
        $check['passed']    = $passed;
        $check['applied']   = false;
        if ($passed && $posting['status'] === 'awaiting_packaging') {
            $posting['exemplars'] = self::confirmed($check['products']);
            $check['applied']     = true;
        }
        $posting['exemplar_check'] = $check;
        unset($posting);
        $state->event($passed ? 'fbs.exemplars.passed' : 'fbs.exemplars.failed', $now, ['posting_number' => $number]);
    }

    /** @return array{float, float} accepted actual weight of one unit, kg: ±20 % of the catalog weight (an emulator choice) */
    public static function weightRange(CabinetState $state, int $sku): array
    {
        $product = array_column($state->config()['products'], null, 'sku')[$sku] ?? [];
        $weight  = (float) ($product['dimensions']['weight'] ?? 0);
        $kg      = ($product['dimensions']['weightUnit'] ?? 'g') === 'kg' ? $weight : $weight / 1000;

        return [round($kg * 0.8, 3), round($kg * 1.2, 3)];
    }

    /** Exemplar ids are created once per unit and kept; a cancelled unit drops the last ones. */
    private function createOrGet(CabinetState $state, string $number): array
    {
        $posting = &$state->data['fbs']['postings'][$number];
        if ($posting['status'] === 'cancelled') {
            unset($posting);

            throw new SellerApiException('Posting ' . $number . ' is cancelled', 400, 3);
        }
        $products = [];
        foreach ($posting['products'] as $line) {
            $sku       = $line['sku'];
            $exemplars = array_slice($posting['exemplars'][$sku] ?? [], 0, $line['quantity']);
            while (count($exemplars) < $line['quantity']) {
                $exemplars[] = ['exemplar_id' => $state->id(), 'marks' => [], 'gtd' => '', 'is_gtd_absent' => false, 'rnpt' => '', 'is_rnpt_absent' => false, 'weight' => 0.0];
            }
            $posting['exemplars'][$sku] = $exemplars;
            [$min, $max]                = self::weightRange($state, $sku);
            $products[]                 = [
                'exemplars'                  => $exemplars, 'has_imei' => FbsRequirements::needs($posting, 'imei', $sku),
                'is_gtd_needed'              => FbsRequirements::needs($posting, 'gtd', $sku), 'is_jw_uin_needed' => FbsRequirements::needs($posting, 'jw_uin', $sku),
                'is_mandatory_mark_needed'   => FbsRequirements::needs($posting, 'mandatory_mark', $sku),
                'is_mandatory_mark_possible' => FbsRequirements::needs($posting, 'mandatory_mark', $sku) || FbsRequirements::needs($posting, 'possible_mark', $sku),
                'is_rnpt_needed'             => FbsRequirements::needs($posting, 'rnpt', $sku), 'product_id' => $sku, 'quantity' => $line['quantity'],
                'is_weight_needed'           => FbsRequirements::needs($posting, 'weight', $sku), 'weight_max' => $max, 'weight_min' => $min,
            ];
        }
        $response = ['multi_box_qty' => $posting['multi_box_qty'] ?? 1, 'posting_number' => $number, 'products' => $products];
        unset($posting);

        return $response;
    }

    /** Synchronous format check of the codes; it does not look at the posting requirements or other postings. */
    private function validate(CabinetState $state, string $number, array $products): array
    {
        $skus   = array_column(self::posting($state, $number)['products'], 'sku');
        $result = [];
        foreach ($products as $product) {
            $sku       = (int) $product['product_id'];
            $exemplars = [];
            $valid     = in_array($sku, $skus, true);
            foreach ($product['exemplars'] as $exemplar) {
                $marks = array_map(function (array $mark): array {
                    $error = $this->markError((string) ($mark['mark_type'] ?? ''), (string) ($mark['mark'] ?? ''));

                    return ['errors' => $error === null ? [] : [$error], 'mark' => (string) ($mark['mark'] ?? ''), 'mark_type' => (string) ($mark['mark_type'] ?? ''), 'valid' => $error === null];
                }, $exemplar['marks'] ?? []);
                $errors = array_values(array_filter([
                    ($exemplar['gtd'] ?? '') === '' ? null : $this->gtdError($exemplar['gtd']),
                    ($exemplar['rnpt'] ?? '') === '' ? null : $this->rnptError($exemplar['rnpt']),
                ]));
                $exemplarValid = $errors === [] && !in_array(false, array_column($marks, 'valid'), true);
                $valid         = $valid && $exemplarValid;
                $exemplars[]   = ['errors' => $errors, 'gtd' => (string) ($exemplar['gtd'] ?? ''), 'marks' => $marks, 'rnpt' => (string) ($exemplar['rnpt'] ?? ''),
                    'valid'                => $exemplarValid, 'weight' => (float) ($exemplar['weight'] ?? 0)];
            }
            $result[] = ['error' => in_array($sku, $skus, true) ? '' : 'PRODUCT_NOT_IN_POSTING', 'exemplars' => $exemplars, 'product_id' => $sku, 'valid' => $valid];
        }

        return ['products' => $result];
    }

    /**
     * Full set of the posting: every product once, every known exemplar of it once, the required data present. Structural
     * mistakes are rejected at once (an emulator choice); the codes themselves are checked asynchronously.
     */
    private function set(CabinetState $state, string $number, array $input, int $now): array
    {
        $posting = self::posting($state, $number);
        if (!in_array($posting['status'], self::EDITABLE, true)) {
            throw new SellerApiException('Exemplars of posting ' . $number . ' cannot be changed in status ' . $posting['status'], 400, 3);
        }
        $lines    = array_column($posting['products'], null, 'sku');
        $products = [];
        foreach ($input['products'] as $product) {
            $sku = (int) $product['product_id'];
            if (!isset($lines[$sku]) || isset($products[$sku])) {
                throw new SellerApiException('Unknown or repeated product_id ' . $sku . ' for posting ' . $number, 400, 3);
            }
            $known = array_column($posting['exemplars'][$sku] ?? [], 'exemplar_id');
            $ids   = array_map(static fn (array $e): int => (int) $e['exemplar_id'], $product['exemplars']);
            if (count($ids) !== $lines[$sku]['quantity'] || count(array_count_values($ids)) !== count($ids) || array_filter($ids, static fn (int $id): bool => !in_array($id, $known, true)) !== []) {
                throw new SellerApiException(sprintf('Product %d needs all %d exemplars of /v6/fbs/posting/product/exemplar/create-or-get once', $sku, $lines[$sku]['quantity']), 400, 3);
            }
            $products[$sku] = ['product_id' => $sku, 'exemplars' => array_map(fn (array $e): array => $this->exemplar($posting, $sku, $e), $product['exemplars'])];
        }
        if (count($products) !== count($lines)) {
            throw new SellerApiException('Pass the full set of products of posting ' . $number, 400, 3);
        }
        $record = &$state->data['fbs']['postings'][$number];
        if (array_key_exists('multi_box_qty', $input)) {
            $record['multi_box_qty'] = (int) $input['multi_box_qty'];
        }
        $delay                    = $state->config()['operationDelaySeconds'];
        $record['exemplar_check'] = ['products' => array_values($products), 'submitted_at' => $now, 'ready_at' => $now + $delay, 'evaluated' => false, 'passed' => false, 'applied' => false];
        unset($record);
        $state->event('fbs.exemplars.submitted', $now, ['posting_number' => $number]);
        $this->settle($state, $number, $now);

        return [];
    }

    private function status(CabinetState $state, string $number): array
    {
        $posting = self::posting($state, $number);
        $check   = $posting['exemplar_check'] ?? null;
        $status  = match (true) {
            !in_array($posting['status'], self::EDITABLE, true) => 'update_not_available',
            $check !== null && !$check['evaluated']             => 'validation_in_process',
            $posting['status'] === 'awaiting_deliver'           => 'update_available',
            default                                             => self::shipReady($posting) ? 'ship_available' : 'ship_not_available',
        };
        $products = $check === null
            ? array_map(static fn (int $sku): array => ['product_id' => $sku, 'exemplars' => $posting['exemplars'][$sku] ?? []], array_column($posting['products'], 'sku'))
            : $check['products'];
        $pending = $check !== null && !$check['evaluated'];

        return ['posting_number' => $number, 'status' => $status, 'products' => array_map(static fn (array $product): array => [
            'product_id' => $product['product_id'],
            'exemplars'  => array_map(static fn (array $e): array => [
                'exemplar_id'         => $e['exemplar_id'], 'gtd' => $e['gtd'], 'is_gtd_absent' => $e['is_gtd_absent'], 'rnpt' => $e['rnpt'], 'is_rnpt_absent' => $e['is_rnpt_absent'],
                'weight'              => $e['weight'],
                'gtd_check_status'    => $pending && $e['gtd'] !== '' ? 'processing' : ($e['gtd_check_status'] ?? ''), 'gtd_error_codes' => $e['gtd_error_codes'] ?? [],
                'rnpt_check_status'   => $pending && $e['rnpt'] !== '' ? 'processing' : ($e['rnpt_check_status'] ?? ''), 'rnpt_error_codes' => $e['rnpt_error_codes'] ?? [],
                'weight_check_status' => $pending && $e['weight'] > 0 ? 'processing' : ($e['weight_check_status'] ?? ''), 'weight_error_codes' => $e['weight_error_codes'] ?? [],
                'marks'               => array_map(static fn (array $m): array => [
                    'check_status' => $pending ? 'processing' : ($m['check_status'] ?? ''), 'error_codes' => $m['error_codes'] ?? [], 'mark' => $m['mark'], 'mark_type' => $m['mark_type'],
                ], $e['marks']),
            ], $product['exemplars']),
        ], $products)];
    }

    /** After assembly a passed check becomes the confirmed exemplars only here. */
    private function update(CabinetState $state, string $number, int $now): array
    {
        $posting = self::posting($state, $number);
        $check   = $posting['exemplar_check'] ?? null;
        $error   = match (true) {
            $posting['status'] !== 'awaiting_deliver' => 'Exemplars can be updated only in status awaiting_deliver',
            $check === null || $check['applied']      => 'No validated exemplar changes to update',
            !$check['evaluated']                      => 'Exemplars are still being validated',
            !$check['passed']                         => 'Exemplar validation failed; fix the data and set it again',
            default                                   => null,
        };
        if ($error !== null) {
            throw new SellerApiException($error . ' (posting ' . $number . ')', 400, 3);
        }
        $state->data['fbs']['postings'][$number]['exemplars']                 = self::confirmed($check['products']);
        $state->data['fbs']['postings'][$number]['exemplar_check']['applied'] = true;
        $state->event('fbs.exemplars.updated', $now, ['posting_number' => $number]);

        return [];
    }

    /** `product_id` is the catalog product id, as in the snapshot; the requirement lists SKUs. */
    private function country(CabinetState $state, string $number, array $input, int $now): array
    {
        $posting = self::posting($state, $number);
        $product = array_column($state->config()['products'], null, 'productId')[(int) $input['product_id']] ?? null;
        $sku     = $product['sku'] ?? 0;
        if ($posting['status'] !== 'awaiting_packaging' || !in_array($sku, array_column($posting['products'], 'sku'), true)) {
            throw new SellerApiException('Country can be set only for a product of an unassembled posting', 400, 3);
        }
        $iso = (string) $input['country_iso_code'];
        if (!in_array($iso, array_column(self::COUNTRIES, 'country_iso_code'), true)) {
            throw new SellerApiException('Unknown country_iso_code ' . $iso, 400, 3);
        }
        $requirements            = FbsRequirements::of($posting);
        $requirements['country'] = array_values(array_filter($requirements['country'], static fn (int $s): bool => $s !== $sku));
        if ($iso !== 'RU' && !in_array($sku, $requirements['gtd'], true)) {
            $requirements['gtd'][] = $sku;
        }
        $record                      = &$state->data['fbs']['postings'][$number];
        $record['requirements']      = $requirements;
        $record['countries'][$sku]   = $iso;
        $record['available_actions'] = FbsPostingGenerator::actions($record);
        unset($record);
        $state->event('fbs.posting.country_set', $now, ['posting_number' => $number, 'sku' => $sku, 'country_iso_code' => $iso]);

        return ['product_id' => (int) $input['product_id'], 'is_gtd_needed' => in_array($sku, $requirements['gtd'], true)];
    }

    /** Normalised submitted exemplar; data for a requirement the product does not have or missing required data is rejected. */
    private function exemplar(array $posting, int $sku, array $input): array
    {
        $marks = array_map(static fn (array $m): array => ['mark' => (string) ($m['mark'] ?? ''), 'mark_type' => (string) ($m['mark_type'] ?? '')], $input['marks'] ?? []);
        $types = array_count_values(array_column($marks, 'mark_type'));
        foreach ($types as $type => $count) {
            $allowed = match ($type) {
                'mandatory_mark' => FbsRequirements::needs($posting, 'mandatory_mark', $sku) || FbsRequirements::needs($posting, 'possible_mark', $sku),
                'imei'           => FbsRequirements::needs($posting, 'imei', $sku),
                'jw_uin'         => FbsRequirements::needs($posting, 'jw_uin', $sku),
                default          => false,
            };
            if (!$allowed || $count > 1 || !in_array($type, self::MARK_TYPES, true)) {
                throw new SellerApiException(sprintf('Mark type %s is not applicable to product %d or repeated', $type, $sku), 400, 3);
            }
        }
        $exemplar = [
            'exemplar_id' => (int) $input['exemplar_id'], 'marks' => $marks, 'gtd' => (string) ($input['gtd'] ?? ''), 'is_gtd_absent' => (bool) ($input['is_gtd_absent'] ?? false),
            'rnpt'        => (string) ($input['rnpt'] ?? ''), 'is_rnpt_absent' => (bool) ($input['is_rnpt_absent'] ?? false), 'weight' => (float) ($input['weight'] ?? 0),
        ];
        $missing = self::missing($posting, $sku, $exemplar);
        if ($missing !== null) {
            throw new SellerApiException(sprintf('Exemplar %d of product %d requires %s', $exemplar['exemplar_id'], $sku, $missing), 400, 3);
        }

        return $exemplar;
    }

    /** @return ?string the first required kind the exemplar lacks */
    private static function missing(array $posting, int $sku, array $exemplar): ?string
    {
        $types = array_column($exemplar['marks'], 'mark_type');

        return match (true) {
            FbsRequirements::needs($posting, 'mandatory_mark', $sku) && !in_array('mandatory_mark', $types, true)      => 'mandatory_mark',
            FbsRequirements::needs($posting, 'imei', $sku) && !in_array('imei', $types, true)                          => 'imei',
            FbsRequirements::needs($posting, 'jw_uin', $sku) && !in_array('jw_uin', $types, true)                      => 'jw_uin',
            FbsRequirements::needs($posting, 'gtd', $sku) && $exemplar['gtd'] === '' && !$exemplar['is_gtd_absent']    => 'gtd',
            FbsRequirements::needs($posting, 'rnpt', $sku) && $exemplar['rnpt'] === '' && !$exemplar['is_rnpt_absent'] => 'rnpt',
            FbsRequirements::needs($posting, 'weight', $sku) && $exemplar['weight'] <= 0                               => 'weight',
            default                                                                                                    => null,
        };
    }

    /** @return array<int, list<array>> confirmed exemplars by SKU without check results */
    private static function confirmed(array $products): array
    {
        $result = [];
        foreach ($products as $product) {
            $result[$product['product_id']] = array_map(static fn (array $e): array => [
                'exemplar_id' => $e['exemplar_id'], 'marks' => array_map(static fn (array $m): array => ['mark' => $m['mark'], 'mark_type' => $m['mark_type']], $e['marks']),
                'gtd'         => $e['gtd'], 'is_gtd_absent' => $e['is_gtd_absent'], 'rnpt' => $e['rnpt'], 'is_rnpt_absent' => $e['is_rnpt_absent'], 'weight' => $e['weight'],
            ], $product['exemplars']);
        }

        return $result;
    }

    /** @return list<array{mark: string, mark_type: string}> */
    private function marks(array $products): array
    {
        $marks = [];
        foreach ($products as $product) {
            foreach ($product['exemplars'] as $exemplar) {
                foreach ($exemplar['marks'] as $mark) {
                    $marks[] = $mark;
                }
            }
        }

        return $marks;
    }

    /** @return list<string> marks confirmed in other not cancelled postings of the cabinet */
    private function foreignMarks(CabinetState $state, string $number): array
    {
        $marks = [];
        foreach ($state->data['fbs']['postings'] ?? [] as $other => $posting) {
            if ((string) $other === $number || $posting['status'] === 'cancelled') {
                continue;
            }
            foreach ($posting['exemplars'] ?? [] as $sku => $exemplars) {
                foreach ($this->marks([['product_id' => $sku, 'exemplars' => $exemplars]]) as $mark) {
                    $marks[] = $mark['mark'];
                }
            }
        }

        return $marks;
    }

    /**
     * «Честный ЗНАК»: GTIN (01 + 14 digits), serial (21 + up to 20 characters) and the crypto tail after the GS character 0x1D;
     * a backslash is not a code character, so the text `\u001d` instead of the character is a format error. IMEI — 15 digits; jewellery UIN — up to 64 digits.
     */
    private function markError(string $type, string $mark): ?string
    {
        return match ($type) {
            'mandatory_mark' => match (true) {
                preg_match('/^01\d{14}21[\x21-\x5B\x5D-\x7E]{1,20}(\x1D[^\\\\]+)?$/sD', $mark) !== 1 => 'INVALID_MARK_FORMAT',
                !str_contains($mark, "\x1D")                                                         => 'CRYPTO_TAIL_REQUIRED',
                default                                                                              => null,
            },
            'imei'   => preg_match('/^\d{15}$/D', $mark) === 1 ? null : 'INVALID_IMEI_FORMAT',
            'jw_uin' => preg_match('/^\d{1,64}$/D', $mark) === 1 ? null : 'INVALID_JW_UIN_FORMAT',
            default  => 'UNKNOWN_MARK_TYPE',
        };
    }

    private function gtdError(string $gtd): ?string
    {
        return preg_match('~^\d{8}/\d{6}/\d{7}$~D', $gtd) === 1 ? null : 'INVALID_GTD_FORMAT';
    }

    private function rnptError(string $rnpt): ?string
    {
        return preg_match('~^\d{8}/\d{6}/\d{7}/\d{1,3}$~D', $rnpt) === 1 ? null : 'INVALID_RNPT_FORMAT';
    }
}
