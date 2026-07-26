-- -------------------------------------------------------------
-- 012 — the Next Day Review (SPEC-coaching §4.1a)
--
-- Late each evening the user sees tomorrow's session and meals, with a chance to call
-- an audible before the day arrives. It exists because the Sunday plan cannot know
-- everything: travel discovered on Wednesday for a Friday session has no other path
-- into the plan, and waiting until Friday morning wastes the day.
--
-- Two things need storing, and neither is the review itself — that is assembled from
-- prescribed_sessions and prescribed_meals on read.
--
--   1. WHEN it appears. §4.1a says "late each evening", which is a per-user opinion in
--      the same way the plan and check-in slots are. Reusing the existing pattern
--      rather than hardcoding 18:00.
--   2. WHETHER IT HAS BEEN SEEN. "Optional and dismissible; it must not become the
--      noise the user was promised they'd be spared" is the load-bearing sentence in
--      §4.1a, and a card that reappears on every page load IS that noise.
-- -------------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'profiles'
      AND COLUMN_NAME = 'review_hour'
);
SET @sql = IF(@col_exists = 0,
    -- Hour in the user's LOCAL reckoning (009 gave profiles a timezone). 20:00 rather
    -- than 18:00: this is about tomorrow, and it wants to land after the evening meal
    -- rather than in the middle of it. Also deliberately AFTER the check-in slot on a
    -- Saturday, so the two do not arrive together.
    --
    -- 0 disables it. A user who does not want to think about tomorrow tonight is
    -- exactly the user §4.1a promises not to nag, and there should be a way to say so
    -- without a separate flag.
    'ALTER TABLE profiles
        ADD COLUMN review_hour TINYINT UNSIGNED NOT NULL DEFAULT 20 AFTER checkin_hour',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

/*
 * Dismissals, one row per (user, day dismissed).
 *
 * A table rather than a column on profiles, because the question is "has tomorrow's
 * review been dismissed" and that answer changes every day. A single
 * `review_dismissed_at` timestamp would have to be compared against a date computed in
 * the user's zone on every read, and clearing it at midnight needs a job that would
 * exist for nothing else.
 *
 * The unique key is the whole mechanism: dismissing twice is a no-op, and tomorrow's
 * review is a different row so it appears again.
 */
CREATE TABLE IF NOT EXISTS review_dismissals (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id  BIGINT UNSIGNED NOT NULL,
    -- The day the review was ABOUT, not the day it was dismissed. Those differ by one
    -- and only the first is stable: a user who dismisses at 23:58 and again at 00:02
    -- would otherwise write two rows about the same tomorrow.
    review_date DATE NOT NULL,
    dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_review_user_date (user_id, review_date),
    CONSTRAINT fk_review_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
