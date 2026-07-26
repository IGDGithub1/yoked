-- -------------------------------------------------------------
-- 015 — the buddy schedule (SPEC-coaching §10.1a, §10.3a, §10.3b)
--
-- A paired user has TWO schedules: the individual §7.1 availability grid answered at
-- onboarding, and the days the pair agreed to train together. The buddy schedule takes
-- priority; the individual one is the fallback and the filler.
--
-- WHY A SEPARATE TABLE RATHER THAN EDITING `availability`.
--
-- Two reasons, both from §10.1a.
--
--   1. A user can agree to a day they never originally offered. Compromise is the point of
--      §10.3a, and it must not require rewriting the answer they gave about their own week.
--      `availability` is the record of what THIS PERSON said they can do; a day they took on
--      for a buddy is a different fact.
--   2. A buddy going quiet must not strand the other (§10.5). Falling back means generating
--      from the individual grid, which only works if that grid is still intact.
--
-- Editing `availability` would also be irreversible in practice: unpairing could not tell
-- which days were originally theirs and which were conceded.
--
-- SCOPE: this migration covers step 2 of §10.7 — schedules and negotiation. Absence
-- (§10.5) and synced sessions (§10.6) are later steps and get their own migrations.
-- -------------------------------------------------------------

-- ---- the agreed days ----------------------------------------------------------
--
-- One row per (pair, weekday). Only days the pair TRAINS TOGETHER are stored, so the
-- absence of a row means "not a shared day" and there is no need for a can_train enum.
--
-- Keyed on the pair rather than the user because that is what it is: an agreement between
-- two people, which vanishes with the pair. ON DELETE CASCADE from buddy_pairs does that
-- for free.

SET @tbl_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'buddy_schedule_days'
);
SET @sql = IF(@tbl_exists = 0,
    'CREATE TABLE buddy_schedule_days (
        buddy_pair_id BIGINT UNSIGNED NOT NULL,
        weekday       TINYINT UNSIGNED NOT NULL,   -- 1=Mon .. 7=Sun (ISO-8601)

        -- The shared session length: the SHORTER of both available minutes, because a
        -- shared session cannot outlast whichever of them has to leave (§10.3).
        -- Nullable when neither user stated a duration.
        --
        -- No apostrophes anywhere in this file: the whole CREATE TABLE is a single-quoted
        -- MySQL string passed to PREPARE, so one apostrophe in a comment terminates the
        -- statement early and the syntax error points at the following line rather than at
        -- the quote that caused it.
        minutes       SMALLINT UNSIGNED NULL,

        -- Was this day in both grids already, or conceded in a negotiation (§10.3a)?
        -- Kept because it changes what the app should say: "you both had Wednesday free"
        -- reads differently from "you agreed to add Wednesday", and a conceded day is the
        -- one worth revisiting if the pairing stops working.
        origin        ENUM(''intersection'',''negotiated'') NOT NULL DEFAULT ''intersection'',

        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (buddy_pair_id, weekday),
        CONSTRAINT fk_bsd_pair FOREIGN KEY (buddy_pair_id)
            REFERENCES buddy_pairs(id) ON DELETE CASCADE,
        CONSTRAINT chk_bsd_weekday CHECK (weekday BETWEEN 1 AND 7)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT "015: buddy_schedule_days already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---- the negotiation ----------------------------------------------------------
--
-- §10.3a: where the intersection is too thin to pair meaningfully, the app asks both users
-- to compromise. A day offered by one has to be visible to the other and accepted by them,
-- which needs somewhere to live between the offer and the answer.
--
-- One row per (pair, weekday, offerer). Both users can offer the same day independently —
-- that is agreement arriving from both directions at once, and it should resolve rather
-- than collide.

SET @tbl_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'buddy_day_offers'
);
SET @sql = IF(@tbl_exists = 0,
    'CREATE TABLE buddy_day_offers (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        buddy_pair_id BIGINT UNSIGNED NOT NULL,
        weekday       TINYINT UNSIGNED NOT NULL,
        -- Who is offering to train on a day that is not in their own grid.
        offered_by    BIGINT UNSIGNED NOT NULL,
        -- Minutes they can manage on that day. Their own figure, not the intersection:
        -- the shared duration is computed when the offer is accepted.
        minutes       SMALLINT UNSIGNED NULL,
        status        ENUM(''pending'',''accepted'',''declined'',''withdrawn'')
                      NOT NULL DEFAULT ''pending'',
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        responded_at  DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_bdo_pair_day_offerer (buddy_pair_id, weekday, offered_by),
        KEY idx_bdo_pending (buddy_pair_id, status),
        CONSTRAINT fk_bdo_pair FOREIGN KEY (buddy_pair_id)
            REFERENCES buddy_pairs(id) ON DELETE CASCADE,
        CONSTRAINT fk_bdo_user FOREIGN KEY (offered_by)
            REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT chk_bdo_weekday CHECK (weekday BETWEEN 1 AND 7)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT "015: buddy_day_offers already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---- what to do with surplus days ---------------------------------------------
--
-- §10.3b: when the buddy schedule covers fewer days than someone's committed count, THAT
-- USER decides what happens to the difference. The app asks; it does not pick.
--
-- Per-user, not per-pair: two people in one pair can answer differently, and usually will —
-- the 5-day user has a surplus to think about and the 3-day user does not.
--
-- NULL means "not asked yet", which is distinct from any of the three answers and is what
-- the UI prompts on. Defaulting to a choice would be making the decision the spec says
-- belongs to the user.

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'profiles'
      AND COLUMN_NAME = 'buddy_surplus_mode'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE profiles
        ADD COLUMN buddy_surplus_mode
            ENUM(''keep_commitment'',''extras_optional'',''match_buddy'') NULL
            COMMENT ''SPEC-coaching 10.3b. NULL = not asked yet.''',
    'SELECT "015: profiles.buddy_surplus_mode already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
