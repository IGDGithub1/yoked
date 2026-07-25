-- =============================================================
-- Migration 006 — cron run log
--
-- Cron fires every 15 minutes, but weekly generation must happen ONCE per
-- user per week. Without a record of what already ran, a 15-minute schedule
-- regenerates every plan four times an hour — burning API spend and, worse,
-- superseding a plan the user may already be following.
--
-- One row per (job, subject, period). The unique key is what makes
-- double-running structurally impossible rather than a code check.
-- =============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS cron_runs (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job      VARCHAR(40) NOT NULL,   -- weekly_plan | weekly_checkin | nudge_sweep
    -- The thing the job acted on: usually a user id, NULL for global jobs.
    user_id  BIGINT UNSIGNED NULL,
    -- The period this run covers. A week_start for weekly jobs, a date for
    -- daily ones. Part of the unique key, so re-running a LATER period is
    -- always allowed while re-running the same one is not.
    period   DATE NOT NULL,

    status   ENUM('running','ok','failed','skipped') NOT NULL DEFAULT 'running',
    detail   VARCHAR(500) NULL,      -- error text, or why it was skipped
    duration_ms INT UNSIGNED NULL,

    started_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,

    PRIMARY KEY (id),
    -- Claimed BEFORE the work starts, so two overlapping cron invocations
    -- cannot both pick up the same job: the second INSERT fails.
    UNIQUE KEY uq_cron_job_subject_period (job, user_id, period),
    KEY idx_cron_job_period (job, period),
    KEY idx_cron_status (status, started_at),
    CONSTRAINT fk_cron_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user coaching schedule. Kept on profiles rather than hardcoded so a
-- user who wants Sunday-evening plans instead of Monday-morning ones is a
-- settings change, not a code change.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'profiles'
      AND COLUMN_NAME = 'plan_generation_weekday'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE profiles
        -- 1=Mon .. 7=Sun. Default 7: generate Sunday so the week is waiting
        -- on Monday morning.
        ADD COLUMN plan_generation_weekday TINYINT UNSIGNED NOT NULL DEFAULT 7,
        -- Hour in the user''s local reckoning; everything is stored UTC and
        -- converted client-side, so this is UTC too until per-user timezones
        -- arrive (deliberately deferred — see SPEC decisions).
        ADD COLUMN plan_generation_hour TINYINT UNSIGNED NOT NULL DEFAULT 18,
        -- Pause coaching without deleting the account.
        ADD COLUMN coaching_paused TINYINT(1) NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
