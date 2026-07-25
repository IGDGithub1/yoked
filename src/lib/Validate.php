<?php
declare(strict_types=1);

/**
 * Input validation. The Friendspace set plus what Yoked's own inputs need.
 *
 * Every method returns the coerced value or null — never throws. Callers decide
 * what a failure means, because "missing" and "invalid" often warrant different
 * responses and a shared exception cannot tell them apart.
 */
final class Validate
{
    /** URL-safe username: 3-30 chars, letters/numbers/underscore, starts with a letter. */
    public static function username(string $v): bool
    {
        return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,29}$/', $v);
    }

    public static function email(string $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_EMAIL) !== false && strlen($v) <= 255;
    }

    /** Trimmed string within length bounds, or null if invalid. */
    public static function str($v, int $min, int $max): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        // mb_strlen, not strlen: a name with an accent should not fail a length
        // check because of byte count.
        $len = mb_strlen($v);
        return ($len >= $min && $len <= $max) ? $v : null;
    }

    /** Positive integer or null. */
    public static function id($v): ?int
    {
        if (is_int($v) && $v > 0) {
            return $v;
        }
        if (is_string($v) && ctype_digit($v) && (int) $v > 0) {
            return (int) $v;
        }
        return null;
    }

    /** Integer within an inclusive range, or null. */
    public static function intRange($v, int $min, int $max): ?int
    {
        if (is_bool($v) || (!is_int($v) && !is_string($v) && !is_float($v))) {
            return null;
        }
        if (is_string($v) && !is_numeric($v)) {
            return null;
        }
        $i = (int) $v;
        return ($i >= $min && $i <= $max) ? $i : null;
    }

    /** Float within an inclusive range, or null. */
    public static function floatRange($v, float $min, float $max): ?float
    {
        if (is_bool($v) || !is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        return ($f >= $min && $f <= $max) ? $f : null;
    }

    /** One of an allowed set, or null. */
    public static function enum($v, array $allowed): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        return in_array($v, $allowed, true) ? $v : null;
    }

    /**
     * A YYYY-MM-DD date that actually exists, or null.
     *
     * checkdate matters: '2026-02-30' passes a regex and is not a date.
     */
    public static function date($v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return null;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
    }

    /** A date that is a Monday — week keys are Monday-start throughout. */
    public static function weekStart($v): ?string
    {
        $d = self::date($v);
        if ($d === null) {
            return null;
        }
        return (int) date('N', strtotime($d)) === 1 ? $d : null;
    }

    /**
     * A list of strings drawn from an allowed set.
     *
     * Returns null on any invalid member rather than silently dropping it — a
     * partially-accepted multi-select is worse than a rejected one, because the
     * user is never told what went missing.
     */
    public static function enumList($v, array $allowed, int $max = 50): ?array
    {
        if (!is_array($v)) {
            return null;
        }
        if (count($v) > $max) {
            return null;
        }
        $out = [];
        foreach ($v as $item) {
            if (!is_string($item) || !in_array($item, $allowed, true)) {
                return null;
            }
            $out[] = $item;
        }
        return array_values(array_unique($out));
    }

    /** A list of free-text strings, each trimmed and length-bounded. */
    public static function strList($v, int $maxItems = 50, int $maxLen = 200): ?array
    {
        if (!is_array($v) || count($v) > $maxItems) {
            return null;
        }
        $out = [];
        foreach ($v as $item) {
            if (!is_string($item)) {
                return null;
            }
            $s = trim($item);
            if ($s === '') {
                continue;   // drop blanks rather than storing empty strings
            }
            if (mb_strlen($s) > $maxLen) {
                return null;
            }
            $out[] = $s;
        }
        return $out;
    }

    /** Loose boolean: accepts true/false, 1/0, "yes"/"no", "true"/"false". */
    public static function bool($v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) && in_array($v, [0, 1], true)) {
            return $v === 1;
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));
            if (in_array($s, ['1', 'true', 'yes', 'on'], true))  { return true; }
            if (in_array($s, ['0', 'false', 'no', 'off'], true)) { return false; }
        }
        return null;
    }

    /**
     * Password floor.
     *
     * Length only, deliberately. Composition rules (a digit, a symbol) push
     * users toward `Password1!` and measurably weaken real-world choices; length
     * is the property that actually matters. 72 bytes is bcrypt's limit —
     * anything beyond is silently ignored, so reject it rather than pretend.
     */
    public static function password($v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $len = strlen($v);   // bytes, because bcrypt's limit is in bytes
        return ($len >= 10 && $len <= 72) ? $v : null;
    }

    /** An IANA-ish timezone string PHP recognises, or null. */
    public static function timezone($v): ?string
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        return in_array($v, timezone_identifiers_list(), true) ? $v : null;
    }
}
