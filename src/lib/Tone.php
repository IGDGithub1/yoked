<?php
declare(strict_types=1);

/**
 * The user's coaching voice (SPEC-onboarding §9.1).
 *
 * Each tone is a named voice with a written character brief that goes into every
 * generation prompt. Per the spec: "The brief is what makes the tone real; the label
 * is just how the user picks it."
 *
 * Extracted from Plans::toneBrief() when nudges needed the same voice. Two copies of
 * a character brief is two voices, and a user whose plans roast them while their
 * nudges are gentle has been given two different coaches.
 */
final class Tone
{
    public const TONES = [
        'sarcastic_hardass', 'high_school_coach', 'motivational_speaker',
        'funny_positive', 'friendly_encouraging', 'direct_no_fluff',
    ];

    /** The character brief for a prompt. Unknown tones fall back to the default. */
    public static function brief(string $tone): string
    {
        return match ($tone) {
            'sarcastic_hardass' =>
                'Dry, teasing, profane-adjacent. Roast excuses, never the person — '
                . "the joke is always at the situation's expense. This applies to "
                . 'food and body too; there is no carve-out.',
            'high_school_coach' =>
                'Results-driven and relentlessly pushing for more. Never satisfied, '
                . 'always in their corner. "Good. Now do it again heavier."',
            'motivational_speaker' =>
                'Big energy, stakes-raising, aspirational. Every session is The Session.',
            'funny_positive' =>
                'Light, warm, genuinely silly. Celebrate small wins without irony.',
            'direct_no_fluff' =>
                'Say the thing and stop. No jokes, no pep, no padding.',
            default =>
                'Calm, patient, supportive. Encouraging without being saccharine.',
        };
    }

    /**
     * How hard a nudge should push, from the user's own nudge_intensity answer.
     *
     * This is a separate axis from tone: a sarcastic hardass who chose
     * 'leave_me_alone' gets one dry line and then silence, and a friendly
     * encourager who chose 'relentless' keeps hearing about it warmly. Collapsing
     * the two would override an explicit answer with a personality.
     */
    public static function nudgeBrief(string $intensity): string
    {
        return match ($intensity) {
            'leave_me_alone' =>
                'ONE short line. Do not ask a question, do not invite a reply. They '
                . 'have explicitly asked to be left alone, so this is a note on a '
                . 'door, not a conversation.',
            'persistent' =>
                'Direct and a little insistent. Name the number of days. Ask what '
                . 'happened.',
            'relentless' =>
                'Insistent and impossible to ignore, without ever being cruel. Name '
                . 'the days, ask directly, make it clear you are not going to quietly '
                . 'forget about this.',
            default =>
                'Gentle and low-pressure. One or two sentences. Leave the door open '
                . 'rather than pushing.',
        };
    }

    /**
     * Strip long dashes out of generated copy.
     *
     * The house style has no em or en dashes: they read as machine-written, which is
     * the one impression this app cannot afford. Asking the model not to use them was
     * tried first and it does not hold — across five tones it ignored an explicit
     * "NO EM DASHES" instruction in two of them, and the failures land in a user's
     * face rather than in a test, because generated copy is exactly what the browser
     * suite never sees.
     *
     * So the instruction stays in the prompt (it reduces the rate) and this enforces
     * it. Spaced dashes become a comma, unspaced ones become ", " so "3 days—no
     * drama" does not turn into "3 daysno drama".
     */
    public static function clean(string $text): string
    {
        // Spaced first, so " — " does not become " , ".
        $out = preg_replace('/\s*[—–]\s*/u', ', ', $text) ?? $text;
        // Collapse any doubled punctuation the substitution created.
        $out = preg_replace('/,\s*,/', ',', $out) ?? $out;
        $out = preg_replace('/([.!?]),/', '$1', $out) ?? $out;
        return trim($out);
    }

    /** How many times we will chase before going quiet, per the user's answer. */
    public static function nudgeCeiling(string $intensity): int
    {
        return match ($intensity) {
            'leave_me_alone' => 1,
            'persistent'     => 4,
            'relentless'     => 7,
            default          => 2,   // gentle
        };
    }
}
