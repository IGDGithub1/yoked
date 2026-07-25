-- -------------------------------------------------------------
-- 010 — the weekly check-in gets a schedule and an outcome
--
-- The check-in job had no weekday/hour gate at all, so it opened the moment the
-- week rolled over: Monday 00:00, SIX HOURS AFTER the Sunday 18:00 plan it is
-- supposed to inform. SPEC-coaching §7.2 says the check-in produces next week's
-- plan, and it could not, because the plan was already written and live before
-- the user had been asked anything.
--
-- The fix is a schedule, and it lives on profiles next to the plan slot it has to
-- stay ahead of: check-in opens Saturday 18:00, plan generates Sunday 18:00, which
-- gives the user roughly 24 hours.
--
-- The rest of this migration is about what happens when they miss that window.
-- The plan generates anyway with the logs and history it has, and a check-in
-- submitted afterwards still gets answered: Claude sees the just-generated plan
-- and decides whether the answers change it. Most weeks they will not. A broken
-- leg does.
-- -------------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'profiles'
      AND COLUMN_NAME = 'checkin_weekday'
);
SET @sql = IF(@col_exists = 0,
    -- 1=Mon .. 7=Sun, matching plan_generation_weekday and PHP's 'N'.
    -- Default 6 (Saturday) at 18:00, a day ahead of the default plan slot.
    --
    -- Local to the user (009): "Saturday evening" has to mean Saturday evening
    -- where they are, or the 24-hour window opens at lunchtime for half of them.
    'ALTER TABLE profiles
        ADD COLUMN checkin_weekday TINYINT UNSIGNED NOT NULL DEFAULT 6
            AFTER plan_generation_hour,
        ADD COLUMN checkin_hour    TINYINT UNSIGNED NOT NULL DEFAULT 18
            AFTER checkin_weekday',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- What became of a check-in, and what it did to the plan.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'weekly_checkins'
      AND COLUMN_NAME = 'answered_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE weekly_checkins
        -- When the USER submitted. completed_at already existed but is set when
        -- the whole exchange finishes, including Claude''s review; this is the
        -- moment the answers arrived, which is what decides late vs on time.
        ADD COLUMN answered_at DATETIME NULL AFTER status,

        -- TRUE when the answers arrived after the plan for the coming week was
        -- already generated. Not derived at read time: the comparison needs the
        -- plan''s creation instant, and a plan can later be superseded, so the
        -- fact is recorded when it is known.
        ADD COLUMN answered_late TINYINT(1) NOT NULL DEFAULT 0 AFTER answered_at,

        -- What Claude did about a late check-in. NULL until one is reviewed.
        --   banked  = noted for next week, this week stands
        --   altered = the plan was superseded with reason = ''check_in''
        ADD COLUMN late_outcome ENUM(''banked'',''altered'') NULL AFTER answered_late,

        -- The plan this check-in fed into, or altered. Lets the history answer
        -- "which plan came out of this conversation" without guessing by date.
        ADD COLUMN plan_version_id BIGINT UNSIGNED NULL AFTER late_outcome,

        -- Nudge bookkeeping (§9 resolved item 2: "cron creates it, nudges if
        -- unanswered"). Counted so escalation has something to escalate on, and
        -- stamped so a nudge cannot fire twice in one sweep.
        ADD COLUMN nudge_count    TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER plan_version_id,
        ADD COLUMN last_nudged_at DATETIME NULL AFTER nudge_count,

        ADD CONSTRAINT fk_checkin_plan FOREIGN KEY (plan_version_id)
            REFERENCES plan_versions(id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The nudge sweep asks "which pending check-ins are old enough to chase?" on
-- every run, so that lookup is indexed rather than scanning every check-in ever
-- written.
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'weekly_checkins'
      AND INDEX_NAME = 'idx_checkin_pending'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE weekly_checkins
        ADD KEY idx_checkin_pending (status, last_nudged_at)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Existing completed check-ins have no answered_at, and reading NULL as "never
-- answered" would make a finished check-in look pending to the nudge sweep.
-- completed_at is the best evidence available for when they were answered.
UPDATE weekly_checkins
   SET answered_at = completed_at
 WHERE status = 'completed'
   AND answered_at IS NULL
   AND completed_at IS NOT NULL;
