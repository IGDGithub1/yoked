-- -------------------------------------------------------------
-- 016 — where a shared session happens
--
-- A gap in 015. Safety::checkAvailability enforces THREE things per training day, not one:
-- the day is not marked unavailable, the session fits the available minutes, and the
-- session location matches that day access.
--
-- 015 gave buddy_schedule_days a weekday and a duration but no location. So a day conceded
-- in a negotiation (SPEC-coaching 10.3a) would clear the day check and then fail the
-- location check, because the only access value available is the one on the concedor grid
-- row for a day they said they cannot train, which is NULL or wrong.
--
-- Both tables need it: the offer carries what the offerer can manage, and the agreed day
-- carries what the pair settled on.
--
-- CLAUSE ORDER: COMMENT comes BEFORE AFTER.
--
-- MySQL column_definition puts COMMENT inside the definition and AFTER outside it as a
-- placement clause, so `... NULL AFTER minutes COMMENT ''x''` is a syntax error while
-- `... NULL COMMENT ''x'' AFTER minutes` is fine. The 1064 quotes the COMMENT text, which
-- makes it read like a quoting problem, and it is not.
--
-- Worth writing down because it cost three wrong diagnoses: apostrophes in comments, then
-- COMMENT clauses in prepared strings, then a semicolon inside a comment string. Each was
-- plausible, each was refuted by probing, and none was the cause. Migration 015 has the same
-- COMMENT-in-a-prepared-string construct and applied cleanly — because it adds a column with
-- no AFTER clause at all.
--
-- One real bug did come out of it: bin/migrate.php treated the first quote of a doubled pair
-- as CLOSING a string rather than escaping a literal, which inverted its idea of
-- inside-vs-outside from that point on. Fixed, and verified directly against
-- splitStatements — it now keeps a doubled-quote string intact even when it contains a
-- semicolon.
-- -------------------------------------------------------------

-- buddy_schedule_days.access: where the shared session happens. Both users must be able to
-- train there, so it is the more restrictive of the two (bodyweight beats full_gym).
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'buddy_schedule_days'
      AND COLUMN_NAME = 'access'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE buddy_schedule_days
        ADD COLUMN access ENUM(''full_gym'',''home_gym'',''bodyweight'',''outdoors'') NULL
            COMMENT ''Where the shared session happens, the more restrictive of the two''
            AFTER minutes',
    'SELECT "016: buddy_schedule_days.access already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- buddy_day_offers.access: where the OFFERER can train on the day they are offering. The
-- shared value is computed against the other user when the offer is accepted.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'buddy_day_offers'
      AND COLUMN_NAME = 'access'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE buddy_day_offers
        ADD COLUMN access ENUM(''full_gym'',''home_gym'',''bodyweight'',''outdoors'') NULL
            COMMENT ''Where the offerer can train on the day being offered''
            AFTER minutes',
    'SELECT "016: buddy_day_offers.access already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
