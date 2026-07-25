<?php
declare(strict_types=1);

/**
 * Absence nudges (SPEC-coaching §9).
 *
 * The whole design constraint is one line of scoping: "I hate noisy apps." So:
 *
 *   | Days quiet | Action                                    |
 *   |------------|-------------------------------------------|
 *   | 1          | Nothing                                   |
 *   | 2          | Passive in-app indicator                  |
 *   | 3 (§9.3)   | Direct nudge, in the user's tone          |
 *   | 5+         | Escalated per the user's nudge_intensity  |
 *
 * NUDGES ADDRESS ABSENCE, NEVER A BAD DAY. A logged bad day is a success and gets
 * nothing at all. That is not a nicety: absence is what actually ends a coaching
 * relationship, and an app that scolds someone for a hard week teaches them to stop
 * logging the hard weeks.
 *
 * The copy is generated rather than templated because it is one short line in a voice
 * the user chose, and six tones times four intensities is a matrix no set of canned
 * strings survives. It is a tiny call — a few hundred tokens with effort 'low' — and
 * there is a template fallback so a model outage never means a silent absence.
 */
final class Nudge
{
    /** Quiet days at which the passive indicator appears, before any nudge. */
    public const PASSIVE_AFTER = 2;

    /** Quiet days at which escalation kicks in, on top of the user's own threshold. */
    public const ESCALATE_AFTER = 5;

    /**
     * Write an absence nudge, if one is due.
     *
     * Returns the notification id, or null when nothing was sent — which is the
     * common case and not a failure. Reasons for null: not quiet long enough, the
     * ceiling for their intensity is reached, or one already went out today.
     */
    public static function forAbsence(int $userId, array $assessment): ?int
    {
        $quiet = (int) $assessment['quiet_days'];

        $profile = DB::one(
            'SELECT tone, nudge_intensity, nudge_after_days FROM profiles WHERE user_id = ?',
            [$userId]
        ) ?? [];

        $intensity = (string) ($profile['nudge_intensity'] ?? 'gentle');
        $threshold = max(1, (int) ($profile['nudge_after_days'] ?? 3));

        // Below their own threshold, the passive indicator is the whole response. The
        // client renders that from the absence state itself, without a notification.
        if ($quiet < $threshold) {
            return null;
        }

        // How many absence nudges have already gone out in this quiet stretch. Counted
        // from the last logged day rather than all-time, so a user who comes back and
        // goes quiet again starts fresh.
        $since = $assessment['last_logged'] ?? null;
        $sent  = (int) (DB::one(
            'SELECT COUNT(*) AS n FROM notifications
             WHERE user_id = ? AND type = "absence"'
            . ($since === null ? '' : ' AND created_at > ?'),
            $since === null ? [$userId] : [$userId, $since . ' 00:00:00']
        )['n'] ?? 0);

        if ($sent >= Tone::nudgeCeiling($intensity)) {
            // Gone quiet enough times. Continuing past the user's own stated
            // tolerance is how an app gets deleted.
            return null;
        }

        $body = self::compose(
            (string) ($profile['tone'] ?? 'friendly_encouraging'),
            $intensity,
            $quiet,
            $quiet >= self::ESCALATE_AFTER,
            $userId
        );

        // dedupeHours stops a sweep every 15 minutes from producing 96 copies.
        return Notify::create($userId, 'absence', $body, null, null, 20);
    }

    /**
     * The line itself.
     *
     * Falls back to a template when the model is unavailable. An absence that goes
     * unremarked because of an outage is the one failure mode this whole ladder
     * exists to prevent, so a plainer line beats no line.
     */
    private static function compose(
        string $tone,
        string $intensity,
        int $quiet,
        bool $escalated,
        int $userId
    ): string {
        $result = Claude::json(self::schema(), [
            'purpose'    => 'other',
            'user_id'    => $userId,
            'max_tokens' => 300,
            // Recall and voice, not reasoning. Low effort keeps a once-a-day line
            // cheap and fast.
            'effort'     => 'low',
            'system'     => implode("\n", [
                'You write a single short in-app nudge to a coaching client who has '
                . 'stopped logging.',
                '',
                'VOICE: ' . Tone::brief($tone),
                'PRESSURE: ' . Tone::nudgeBrief($intensity),
                '',
                'Absolute rules:',
                '- Address the ABSENCE, never a bad day. You do not know why they went '
                . 'quiet and you must not guess or assume it went badly.',
                '- Never shame them. Never imply they have failed or fallen off.',
                '- No guilt, no disappointment, no "I noticed you..." passive '
                . 'aggression.',
                '- Under 200 characters. This is a line in a list, not a message.',
                '- No greeting, no signature, no emoji.',
                // The house voice. Generated copy is the one place the browser suite
                // cannot enforce this, since it only ever sees seeded fixtures.
                '- NO EM DASHES and no en dashes. Use a comma or a full stop.',
            ]),
            'messages'   => [[
                'role'    => 'user',
                'content' => "They have not logged anything for {$quiet} days."
                    . ($escalated
                        ? ' This has gone on a while, so this nudge is an escalation.'
                        : ' This is an early nudge.'),
            ]],
        ]);

        // Tone::clean strips em dashes the model produced anyway. The prompt asks
        // for none and reduces the rate; this is what makes it true.
        $line = Tone::clean((string) ($result['data']['nudge'] ?? ''));
        if (($result['ok'] ?? false) && $line !== '') {
            return $line;
        }

        return self::fallback($quiet);
    }

    /** Plain, tone-free, and never shaming. Used only when generation fails. */
    private static function fallback(int $quiet): string
    {
        return $quiet >= self::ESCALATE_AFTER
            ? "It has been {$quiet} days. Log anything at all and your coach can pick "
              . 'the thread back up.'
            : "Nothing logged for {$quiet} days. Whenever you are ready.";
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            // Required on every object by the current model family.
            'additionalProperties' => false,
            'required' => ['nudge'],
            'properties' => [
                'nudge' => [
                    'type' => 'string',
                    'description' => 'The nudge, under 200 characters, in the '
                        . 'specified voice. No greeting or signature.',
                ],
            ],
        ];
    }
}
