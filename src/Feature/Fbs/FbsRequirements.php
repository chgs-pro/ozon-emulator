<?php

declare(strict_types=1);

namespace App\Feature\Fbs;

use function array_fill_keys;
use function array_filter;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_values;
use function in_array;

/**
 * Metadata requirements of a posting, fixed when the posting is created: the SKUs that need a «Честный ЗНАК» mark, the country
 * of origin, a customs declaration (GTD), a batch number (RNPT), the actual weight, IMEI or a jewellery UIN, and the SKUs whose
 * mark is optional. A posting keeps them in `requirements` by kind; old postings had only `marked_skus`.
 */
final class FbsRequirements
{
    /** Posting requirement kind => cabinet configuration key (`fbs.<key>`). */
    public const array CONFIG_KEYS = [
        'mandatory_mark' => 'markedSkus', 'possible_mark' => 'possibleMarkSkus', 'country' => 'countrySkus', 'gtd' => 'gtdSkus',
        'rnpt'           => 'rnptSkus', 'weight' => 'weightSkus', 'imei' => 'imeiSkus', 'jw_uin' => 'jwUinSkus',
    ];

    /** Kinds that are filled per exemplar through `/v6/fbs/posting/product/exemplar/set`. */
    public const array EXEMPLAR_KINDS = ['mandatory_mark', 'gtd', 'rnpt', 'weight', 'imei', 'jw_uin'];

    /**
     * @param list<int> $skus SKUs of the posting
     *
     * @return array<string, list<int>>
     */
    public static function fromConfig(array $fbs, array $skus): array
    {
        return array_map(static fn (string $key): array => array_values(array_filter($skus, static fn (int $sku): bool => in_array($sku, $fbs[$key] ?? [], true))), self::CONFIG_KEYS);
    }

    /** @return array<string, list<int>> every kind, empty when the posting does not require it */
    public static function of(array $posting): array
    {
        $requirements = $posting['requirements'] ?? ['mandatory_mark' => $posting['marked_skus'] ?? []];

        return array_intersect_key($requirements, self::CONFIG_KEYS) + array_fill_keys(array_keys(self::CONFIG_KEYS), []);
    }

    public static function needs(array $posting, string $kind, int $sku): bool
    {
        return in_array($sku, self::of($posting)[$kind], true);
    }

    /** Whether some product of the posting needs exemplar data before `ship`. */
    public static function needsExemplars(array $posting): bool
    {
        $requirements = self::of($posting);
        foreach (self::EXEMPLAR_KINDS as $kind) {
            if ($requirements[$kind] !== []) {
                return true;
            }
        }

        return false;
    }

    /** `requirements` and `optional` blocks of a v4 posting: SKUs as strings, like the contract. */
    public static function v4(array $posting): array
    {
        $r       = self::of($posting);
        $strings = static fn (array $skus): array => array_map('strval', $skus);

        return [
            'requirements' => [
                'products_requiring_mandatory_mark' => $strings($r['mandatory_mark']), 'products_requiring_country' => $strings($r['country']),
                'products_requiring_change_country' => [], 'products_requiring_gtd' => $strings($r['gtd']), 'products_requiring_rnpt' => $strings($r['rnpt']),
                'products_requiring_jw_uin'         => $strings($r['jw_uin']), 'products_requiring_imei' => $strings($r['imei']),
                'products_requiring_weight'         => $strings($r['weight']),
            ],
            'optional' => ['products_with_possible_mandatory_mark' => $strings($r['possible_mark'])],
        ];
    }
}
