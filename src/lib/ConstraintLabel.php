<?php
declare(strict_types=1);

/**
 * Turning a stored constraint into something a person can read.
 *
 * Subjects are written for the GENERATOR, not for a screen: `diabetes_t2`,
 * `dietary_pattern:vegan`, `stair-machine`, `protein_g`. That is correct — the prompt and
 * the validator both match on those strings — but shown verbatim in a profile they read as
 * a database leak, which is exactly what a user reported seeing.
 *
 * DISPLAY ONLY, AND THAT BOUNDARY IS LOAD-BEARING.
 *
 * Safety::promptBlock interpolates `subject` straight into the prompt, and for food it
 * looks the subject up in FOOD_CATEGORIES to expand a category into its members: "shellfish"
 * becomes "shrimp, prawn, crab, lobster…" because validation rejects shrimp and the prompt
 * has to say shrimp. That lookup is an exact lowercase key match. Relabel "shellfish" to
 * "Shellfish" anywhere Safety can see it and the expansion silently stops happening, which
 * is the one failure in this codebase with a path to hurting somebody.
 *
 * So nothing here ever writes to the database, and nothing here is called from Safety. The
 * label rides ALONGSIDE the subject in the two display endpoints, and the subject itself is
 * still sent, because the client round-trips it back when confirming a tier.
 */
final class ConstraintLabel
{
    /**
     * The condition keys from onboarding 3.2, and their question labels.
     *
     * These must agree with app/src/questions.js:3.2 and with the guidance map in
     * Onboarding::extractConditions. Three copies is one too many, but the alternative is
     * the client importing PHP or the prompt importing UI strings.
     */
    private const CONDITIONS = [
        'diabetes_t1'  => 'Type 1 diabetes',
        'diabetes_t2'  => 'Type 2 diabetes',
        'heart'        => 'Heart condition',
        'hypertension' => 'High blood pressure',
        'thyroid'      => 'Thyroid condition',
        'pcos'         => 'PCOS',
        'gi'           => 'IBS or another gut condition',
        'joint'        => 'Joint problems',
    ];

    /** Dietary patterns from 4.3, stored prefixed. */
    private const PATTERNS = [
        'vegetarian'  => 'Vegetarian',
        'vegan'       => 'Vegan',
        'pescatarian' => 'Pescatarian',
        'halal'       => 'Halal',
        'kosher'      => 'Kosher',
        'keto'        => 'Keto or low carb',
        'paleo'       => 'Paleo',
        'other'       => 'A dietary pattern you described',
    ];

    /** Cardio option values from 6.8/6.9. Hyphenated, which is what renders badly. */
    private const CARDIO = [
        'running'        => 'Running',
        'rower'          => 'Rowing',
        'treadmill'      => 'Treadmill',
        'elliptical'     => 'Elliptical',
        'stair-machine'  => 'Stair machine',
        'swimming'       => 'Swimming',
        'fitness-class'  => 'Classes',
        'walking'        => 'Walking',
        'hiking'         => 'Hiking',
        'recumbent-bike' => 'Recumbent bike',
        'upright-bike'   => 'Upright bike',
        'cycling'        => 'Cycling',
        'pickleball'     => 'Pickleball',
        'tennis'         => 'Tennis',
    ];

    /** target_floor subjects are macro keys. */
    private const MACROS = [
        'protein_g'  => 'Protein',
        'calories'   => 'Calories',
        'fat_g'      => 'Fat',
        'carbs_g'    => 'Carbs',
    ];

    /**
     * A readable name for one constraint.
     *
     * Falls back to a tidied version of the raw subject rather than to nothing: allergies,
     * refused foods, injuries and disliked movements are all free text with no closed
     * vocabulary, and so are anything a veto promoted. "left knee" needs no map.
     */
    public static function of(string $kind, string $subject): string
    {
        $s = trim($subject);

        // The one compound subject. Stored prefixed so the generator can tell a way of
        // eating from a banned ingredient.
        if (str_starts_with($s, 'dietary_pattern:')) {
            $p = substr($s, strlen('dietary_pattern:'));
            return self::PATTERNS[$p] ?? self::tidy($p);
        }

        $byKind = match ($kind) {
            'condition'    => self::CONDITIONS,
            'cardio'       => self::CARDIO,
            'target_floor' => self::MACROS,
            default        => [],
        };
        if (isset($byKind[strtolower($s)])) {
            return $byKind[strtolower($s)];
        }

        return self::tidy($s);
    }

    /**
     * What this constraint MEANS, in the user's terms.
     *
     * Not the same question as the label, and the reason the profile card was confusing:
     * grouping everything under "what your coach avoids" put `diabetes_t2` under a heading
     * saying "Never", which reads as though the app is avoiding the user's diabetes.
     *
     * Four genuinely different things live in this table:
     *
     *   avoid    food / movement / cardio. A ban. The only kind the heading fitted.
     *   manage   condition. A MODIFIER carrying guidance, explicitly not a ban — the code
     *            says "diabetes means carb timing matters, not that carbs are banned".
     *   eating   a dietary pattern. How the user eats, not something kept away from them.
     *   floor    target_floor. A MINIMUM. The exact opposite of avoidance.
     */
    public static function facet(string $kind, string $subject): string
    {
        if (str_starts_with(trim($subject), 'dietary_pattern:')) {
            return 'eating';
        }
        return match ($kind) {
            'condition'    => 'manage',
            'target_floor' => 'floor',
            default        => 'avoid',
        };
    }

    /**
     * One line saying what the app does about it, written per facet and tier.
     *
     * Replaces a single sentence that assumed everything was a ban. A user reading
     * "Type 2 diabetes — never prescribed" would reasonably wonder what that meant.
     */
    public static function meaning(string $facet, string $tier): string
    {
        return match ($facet) {
            'manage' => 'Your coach plans around this every week. It is not something being '
                      . 'kept off your plan, it changes how the plan is built.',
            'eating' => $tier === 'hard'
                ? 'Every meal you are given fits this. It is not bent for convenience.'
                : 'Your meals follow this, and your coach may suggest otherwise with a reason.',
            'floor'  => 'A minimum your coach will not go below.',
            default  => $tier === 'hard'
                ? 'Never prescribed. Enforced in code; a plan that breaks it is rejected.'
                : 'Strongly avoided. Can be suggested with a reason, and you can turn it down.',
        };
    }

    /** De-slug a free-text or unmapped subject: "stair-machine" -> "Stair machine". */
    private static function tidy(string $s): string
    {
        $s = str_replace(['_', '-'], ' ', strtolower(trim($s)));
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
