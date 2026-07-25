-- -------------------------------------------------------------
-- 009 — per-user local time, and a baseline that has a clock
--
-- Two problems, both about scheduling.
--
-- ONE: plan_generation_hour was documented as "UTC until per-user timezones
-- arrive". They have arrived. 18:00 UTC is Saturday lunchtime in Chicago and
-- 06:00 Sunday in Sydney, so a schedule meant to read as "late in the weekend,
-- when the user has time" only did so in Europe. Storage stays UTC throughout;
-- the timezone converts WHEN A SLOT FIRES and nothing else.
--
-- TWO: onboarding_state = 'baseline' was a flag with no dates attached, so:
--   - nothing could say how far through the two weeks a user was
--   - nothing ever moved anyone to 'active'
--   - cron treated baseline and active users identically, which means a
--     baseline user got a full prescribed week on their first Sunday. That
--     directly contradicts SPEC-coaching §9 ("Week 1: pure observation. Log
--     food, activity, daily check-ins. No prescription.")
--
-- The dates are stored rather than derived because the whole lifecycle keys off
-- them: no plan during week 1, provisional after week 1, real plan and 'active'
-- at the end. Deriving them from a start date in each of those places is three
-- chances to disagree.
-- -------------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'profiles'
      AND COLUMN_NAME = 'timezone'
);
SET @sql = IF(@col_exists = 0,
    -- IANA identifier, e.g. 'America/New_York'. Validated against PHP's
    -- timezone_identifiers_list() on the way in (Validate::timezone), so this is
    -- a name PHP can actually construct a DateTimeZone from.
    --
    -- NULL means "not detected yet", and the schedule falls back to UTC for that
    -- user rather than guessing. A wrong guess fires the weekend slot on the
    -- wrong day; UTC at least fires predictably.
    'ALTER TABLE profiles
        ADD COLUMN timezone VARCHAR(64) NULL AFTER units',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The baseline clock.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'baseline_starts_on'
);
SET @sql = IF(@col_exists = 0,
    -- On users rather than profiles: this is lifecycle state sitting next to
    -- onboarding_state, which is the thing it qualifies.
    --
    -- Both DATE, both in the user's LOCAL reckoning, aligned to a Monday. A
    -- Thursday signup logs Thursday through Sunday as practice that does not
    -- count, then gets two clean weeks. Partial first weeks make the "week 1 vs
    -- week 2" distinction meaningless, and week 1 is the one with no plan.
    'ALTER TABLE users
        ADD COLUMN baseline_starts_on DATE NULL AFTER onboarding_state,
        ADD COLUMN baseline_ends_on   DATE NULL AFTER baseline_starts_on',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Cron sweeps every 15 minutes asking "whose baseline ended?", so that lookup is
-- indexed rather than a full scan of users on every sweep. Cheap now, and the
-- alternative is a scan that grows with signups.
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_baseline_end'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE users ADD KEY idx_users_baseline_end (onboarding_state, baseline_ends_on)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill anyone already sitting in baseline.
--
-- They started before this migration existed, so there is no record of when.
-- Their account creation date is the only evidence available, and rounding it
-- forward to the following Monday matches what startBaseline() will now do.
-- Without this they would sit in baseline forever, since the transition to
-- 'active' keys off baseline_ends_on being non-null.
--
-- WEEKDAY() is 0=Monday. DAYOFWEEK() is 1=SUNDAY and the obvious
-- (8 - DAYOFWEEK) % 7 expression lands on the wrong day entirely — it returned
-- the Sunday BEFORE the Monday for all seven weekdays. Verified against PHP's
-- strtotime('next monday') for every day of the week before this shipped.
-- The IF() guard is what makes a Monday mean "next Monday" rather than "today".
UPDATE users
   SET baseline_starts_on = DATE_ADD(DATE(created_at),
           INTERVAL IF(WEEKDAY(DATE(created_at)) = 0, 7,
                       7 - WEEKDAY(DATE(created_at))) DAY),
       baseline_ends_on = DATE_ADD(
           DATE_ADD(DATE(created_at),
               INTERVAL IF(WEEKDAY(DATE(created_at)) = 0, 7,
                           7 - WEEKDAY(DATE(created_at))) DAY),
           INTERVAL 14 DAY)
 WHERE onboarding_state = 'baseline'
   AND baseline_starts_on IS NULL;
