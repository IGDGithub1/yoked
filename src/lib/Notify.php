<?php
declare(strict_types=1);

/**
 * In-app notifications, including nudges (SPEC-coaching §9).
 *
 * The `notifications` table has existed since migration 001 and nothing has ever
 * written to it; cron carries a comment saying "Notify::create() would be the home
 * for this once the notification layer lands". This is that.
 *
 * IN-APP ONLY, deliberately. §9 says web push is a possible later addition but not
 * worth VAPID keys and iOS home-screen installation for four users who open the app
 * anyway. So a nudge is a row the client reads, not a push.
 *
 * The tone rule that governs everything here:
 *
 *   > Nudges never shame a bad day. They address ABSENCE, which is the thing that
 *   > actually ends the coaching relationship. A logged bad day is a success.
 *
 * That is why there is no notification type for "you missed a session" or "you went
 * over on calories". Those are the weekly check-in's business. What gets a nudge is
 * silence.
 */
final class Notify
{
    /**
     * Types, kept short because they are matched on by the client.
     *
     *   absence        the user has gone quiet (§9)
     *   checkin_open   the weekly check-in is waiting
     *   checkin_review the coach has written back
     *   plan_ready     a new week is available
     *   drift_question the coach is asking about a rough patch (§7.1)
     */
    public const TYPES = [
        'absence', 'checkin_open', 'checkin_review', 'plan_ready', 'drift_question',
    ];

    /**
     * Write a notification.
     *
     * `dedupeHours` suppresses a repeat of the same (type, subject) inside a window,
     * which is what stops a cron sweeping every 15 minutes from producing 96 copies
     * of the same nudge. Passing null disables it for things that genuinely should
     * appear every time.
     */
    public static function create(
        int $userId,
        string $type,
        string $body,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $dedupeHours = 20
    ): ?int {
        $type = Validate::enum($type, self::TYPES);
        if ($type === null) {
            // A typo'd type would be invisible to the client forever, so it fails
            // loudly here rather than writing a row nothing will ever match.
            throw new InvalidArgumentException('Unknown notification type.');
        }

        if ($dedupeHours !== null && self::recentlySent($userId, $type, $subjectId, $dedupeHours)) {
            return null;
        }

        return DB::insert(
            'INSERT INTO notifications (user_id, actor_id, type, subject_type, subject_id, body)
             VALUES (?, NULL, ?, ?, ?, ?)',
            [$userId, $type, $subjectType, $subjectId, mb_substr($body, 0, 500)]
        );
    }

    private static function recentlySent(int $userId, string $type, ?int $subjectId, int $hours): bool
    {
        $sql = 'SELECT 1 AS x FROM notifications
                WHERE user_id = ? AND type = ?
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)';
        $params = [$userId, $type, $hours];

        // A null subject means "any of this type", which is right for absence
        // nudges: the subject is the silence itself, not a row.
        if ($subjectId !== null) {
            $sql .= ' AND subject_id = ?';
            $params[] = $subjectId;
        }
        $sql .= ' LIMIT 1';

        return DB::one($sql, $params) !== null;
    }

    /** Unread first, newest first. What the client polls. */
    public static function unread(int $userId, int $limit = 20): array
    {
        $out = [];
        foreach (DB::all(
            'SELECT id, type, subject_type, subject_id, body, created_at
             FROM notifications
             WHERE user_id = ? AND read_at IS NULL
             ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)),
            [$userId]
        ) as $r) {
            $out[] = [
                'id'           => (int) $r['id'],
                'type'         => (string) $r['type'],
                'subject_type' => $r['subject_type'],
                'subject_id'   => $r['subject_id'] === null ? null : (int) $r['subject_id'],
                'body'         => (string) $r['body'],
                'created_at'   => (string) $r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * Mark as read.
     *
     * Scoped to the user in the WHERE clause so one user cannot dismiss another's,
     * which matters because ids are sequential and guessable.
     */
    public static function markRead(int $userId, array $ids): int
    {
        $ids = array_values(array_filter(array_map(
            static fn($v): ?int => Validate::id($v),
            $ids
        ), static fn($v): bool => $v !== null));

        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return DB::run(
            "UPDATE notifications SET read_at = NOW()
             WHERE user_id = ? AND read_at IS NULL AND id IN ({$in})",
            array_merge([$userId], $ids)
        )->rowCount();
    }

    /** Everything, read or not. For a history view. */
    public static function markAllRead(int $userId): int
    {
        return DB::run(
            'UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        )->rowCount();
    }
}
