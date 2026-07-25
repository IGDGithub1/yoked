<?php
declare(strict_types=1);

/**
 * Finding a food: AI search, and barcode lookup via Open Food Facts.
 *
 * Both ported from Keto Tracker (SPEC-nutrition.md §3, §4) with the changes that
 * spec calls for:
 *
 *   - Parsed SERVER-SIDE. The original returned raw model output to the browser,
 *     which then stripped ```json fences and JSON.parse'd it. Anything the model
 *     said became the client's problem.
 *   - Rate limited. It is a paid external call and the original had no cap.
 *   - Barcode lookup proxied and cached, not called from the browser. The same
 *     packaged foods get scanned repeatedly.
 *
 * The numbers are model estimates, not database facts. Fine for a handful of
 * users; noted in the spec as worth revisiting if this ever grows.
 */
final class FoodSearch
{
    /** Per user per hour. Generous enough to log a day's food, tight enough to matter. */
    private const SEARCH_LIMIT = 60;

    /** A cached barcode older than this is re-fetched; products get reformulated. */
    private const CACHE_DAYS = 90;

    private const OFF_ENDPOINT = 'https://world.openfoodfacts.org/api/v2/product/';

    /**
     * The shape the model must return. Structured output rather than "reply with
     * JSON", so a fenced block or an apology is not something we have to parse.
     */
    private static function schema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['foods'],
            'properties' => [
                'foods' => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        // Every field required: an optional macro comes back
                        // missing and silently becomes zero, which is worse than
                        // a wrong estimate because it looks deliberate.
                        'required' => ['name', 'serving_g', 'calories', 'protein',
                                       'fat', 'total_carbs', 'fiber'],
                        'properties' => [
                            'name'        => ['type' => 'string'],
                            'serving_g'   => ['type' => 'number'],
                            'calories'    => ['type' => 'number'],
                            'protein'     => ['type' => 'number'],
                            'fat'         => ['type' => 'number'],
                            'total_carbs' => ['type' => 'number'],
                            'fiber'       => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * AI food search.
     *
     * Multi-food queries ("6oz chicken and 1 cup broccoli") return multiple
     * results — that is why the response is an array, and it is the reason the
     * original was pleasant to use.
     */
    public static function search(int $userId, string $query): array
    {
        $q = Validate::str($query, 1, 200);
        if ($q === null) {
            return ['ok' => false, 'error' => 'Type what you ate.'];
        }

        if (!RateLimit::allow('foodsearch:' . $userId, self::SEARCH_LIMIT, 3600)) {
            return ['ok' => false, 'status' => 429,
                    'error' => 'That is a lot of searches in an hour. Try again shortly, '
                               . 'or add the food manually.'];
        }

        $result = Claude::json(self::schema(), [
            'purpose'    => 'food_search',
            'user_id'    => $userId,
            'max_tokens' => 2000,
            // Nutrition lookup is recall, not reasoning. Low effort keeps it
            // fast and cheap, which matters when logging a meal.
            'effort'     => 'low',
            'system'     => 'You are a nutrition database. Use accurate USDA-level data. '
                            . 'If a quantity or portion is given, scale the values to it. '
                            . 'If none is given, use one standard serving. When the query '
                            . 'names several foods, return one object per food. Report '
                            . 'total carbohydrate and dietary fiber separately — net carbs '
                            . 'are derived downstream, so do not subtract fiber yourself.',
            'messages'   => [['role' => 'user', 'content' => $q]],
        ]);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'Could not look that up right now. '
                                              . 'You can still add it manually.'];
        }

        $foods = [];
        foreach ((array) ($result['data']['foods'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $shaped = self::shape($f, 'ai');
            if ($shaped !== null) {
                $foods[] = $shaped;
            }
        }

        if ($foods === []) {
            return ['ok' => false, 'error' => 'No results found. Try different wording.'];
        }
        return ['ok' => true, 'foods' => $foods];
    }

    /**
     * Barcode lookup: cache, then Open Food Facts, then AI on the UPC.
     *
     * The AI fallback is from the original and worth keeping — Open Food Facts
     * coverage is patchy outside Europe, and a scan that finds nothing is a dead
     * end for the user.
     */
    public static function barcode(int $userId, string $upc): array
    {
        // Digits only: this goes into a URL, and a barcode is never anything else.
        $code = preg_replace('/\D+/', '', $upc) ?? '';
        if ($code === '' || strlen($code) > 32) {
            return ['ok' => false, 'error' => 'That does not look like a barcode.'];
        }

        $cached = DB::one(
            'SELECT * FROM food_barcodes WHERE upc = ? AND fetched_at > DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$code, self::CACHE_DAYS]
        );
        if ($cached !== null) {
            return ['ok' => true, 'cached' => true, 'foods' => [self::fromCache($cached)]];
        }

        $off = self::fetchOpenFoodFacts($code);
        if ($off !== null) {
            self::cache($code, $off, 'openfoodfacts');
            return ['ok' => true, 'cached' => false, 'foods' => [self::fromCache($off + ['upc' => $code])]];
        }

        // Not in Open Food Facts. Ask the model about the number itself — it
        // sometimes knows the product, and a wrong guess the user can correct
        // beats a dead end.
        $ai = self::search($userId, "the packaged food product with barcode {$code}");
        if (($ai['ok'] ?? false) && ($ai['foods'] ?? []) !== []) {
            $first = $ai['foods'][0];
            self::cache($code, [
                'name'         => $first['name'],
                'serving_g'    => $first['serving_g'],
                'cal_100g'     => null, 'protein_100g' => null,
                'fat_100g'     => null, 'carbs_100g'   => null, 'fiber_100g' => null,
            ], 'ai');
            foreach ($ai['foods'] as &$f) {
                $f['source']     = 'barcode';
                $f['source_ref'] = $code;
            }
            unset($f);
            return ['ok' => true, 'cached' => false, 'guessed' => true, 'foods' => $ai['foods']];
        }

        return ['ok' => false, 'status' => 404,
                'error' => 'That barcode is not in the database. Add the food by hand '
                           . 'and it will be there next time.'];
    }

    // ---- Open Food Facts ----------------------------------------------------

    /**
     * Per-100g values from Open Food Facts, scaled to one serving.
     *
     * A missing serving_quantity defaults to 100g, matching the original.
     */
    private static function fetchOpenFoodFacts(string $upc): ?array
    {
        $url = self::OFF_ENDPOINT . rawurlencode($upc)
             . '?fields=product_name,nutriments,serving_quantity,serving_size';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            // Open Food Facts asks identifying apps to say who they are.
            CURLOPT_USERAGENT      => 'Yoked/1.0 (personal training app)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            return null;
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json) || (int) ($json['status'] ?? 0) !== 1) {
            return null;
        }

        $p = $json['product'] ?? [];
        $n = $p['nutriments'] ?? [];
        $name = Validate::str($p['product_name'] ?? null, 1, 200);
        if ($name === null) {
            return null;   // a product with no name is not usable
        }

        $num = static function ($v): ?float {
            return is_numeric($v) ? (float) $v : null;
        };

        return [
            'name'         => $name,
            'serving_g'    => ($s = $num($p['serving_quantity'] ?? null)) === null
                              ? 100 : max(1, (int) round($s)),
            'cal_100g'     => $num($n['energy-kcal_100g'] ?? null) ?? $num($n['energy-kcal'] ?? null),
            'protein_100g' => $num($n['proteins_100g'] ?? null),
            'fat_100g'     => $num($n['fat_100g'] ?? null),
            'carbs_100g'   => $num($n['carbohydrates_100g'] ?? null),
            'fiber_100g'   => $num($n['fiber_100g'] ?? null),
        ];
    }

    private static function cache(string $upc, array $d, string $source): void
    {
        DB::run(
            'INSERT INTO food_barcodes
                (upc, name, serving_g, cal_100g, protein_100g, fat_100g, carbs_100g,
                 fiber_100g, source, fetched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                name = VALUES(name), serving_g = VALUES(serving_g),
                cal_100g = VALUES(cal_100g), protein_100g = VALUES(protein_100g),
                fat_100g = VALUES(fat_100g), carbs_100g = VALUES(carbs_100g),
                fiber_100g = VALUES(fiber_100g), source = VALUES(source),
                fetched_at = NOW()',
            [
                $upc, $d['name'], $d['serving_g'] ?? null,
                $d['cal_100g'] ?? null, $d['protein_100g'] ?? null,
                $d['fat_100g'] ?? null, $d['carbs_100g'] ?? null, $d['fiber_100g'] ?? null,
                $source,
            ]
        );
    }

    /** A cached per-100g row scaled to its serving size, ready to log. */
    private static function fromCache(array $r): array
    {
        $serving = (int) ($r['serving_g'] ?? 100);
        $scale   = $serving / 100;

        $at = static function ($v) use ($scale): ?float {
            return $v === null ? null : (float) $v * $scale;
        };

        return [
            'name'        => (string) $r['name'],
            'serving_g'   => $serving,
            'calories'    => round($at($r['cal_100g'] ?? null) ?? 0.0),
            'protein'     => round($at($r['protein_100g'] ?? null) ?? 0.0, 1),
            'fat'         => round($at($r['fat_100g'] ?? null) ?? 0.0, 1),
            // total_carbs and fiber are handed over untouched; Nutrition derives
            // net at intake so there is exactly one place that rule lives.
            'total_carbs' => ($v = $at($r['carbs_100g'] ?? null)) === null ? null : round($v, 1),
            'fiber'       => ($v = $at($r['fiber_100g'] ?? null)) === null ? null : round($v, 1),
            'source'      => 'barcode',
            'source_ref'  => (string) ($r['upc'] ?? ''),
        ];
    }

    /** Coerce one model-supplied food into the client-facing shape. */
    private static function shape(array $f, string $source): ?array
    {
        $name = Validate::str($f['name'] ?? null, 1, 200);
        if ($name === null) {
            return null;
        }
        $num = static function ($v): float {
            return is_numeric($v) ? max(0.0, (float) $v) : 0.0;
        };
        return [
            'name'        => $name,
            'serving_g'   => (int) round($num($f['serving_g'] ?? null)),
            'calories'    => round($num($f['calories'] ?? null)),
            'protein'     => round($num($f['protein'] ?? null), 1),
            'fat'         => round($num($f['fat'] ?? null), 1),
            'total_carbs' => round($num($f['total_carbs'] ?? null), 1),
            'fiber'       => round($num($f['fiber'] ?? null), 1),
            'source'      => $source,
        ];
    }
}
