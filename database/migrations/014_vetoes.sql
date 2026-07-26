-- -------------------------------------------------------------
-- 014 — vetoes (SPEC-coaching §5)
--
-- The `vetoes` table has existed since 004, fully shaped: subject_type, reason_code,
-- scope, outcome, promoted_constraint_id, resulting_plan_version_id. Nothing has ever
-- written a row. `user_constraints.source` has had a 'veto_promotion' member since 002
-- with the comment "the one automated write path, and it can only ever create SOFT
-- constraints", and nothing has ever written that either. This migration adds only what
-- those tables cannot express.
--
-- 1. PER-ROW VETO MARKING on prescriptions.
--
--    SPEC-coaching §3 is explicit: "prescriptions are never overwritten. A vetoed meal
--    stays in the record, marked vetoed, with its reason and replacement." Plan
--    versioning already gives immutability — the old version is superseded, never
--    deleted, and its children survive — so the RECORD is safe today. What is missing is
--    the marking. Without it, the only way to know a superseded meal was rejected rather
--    than merely re-planned is to join back through vetoes.subject_id and guess, and
--    "which of these two dinners did the user actually refuse" is exactly the question a
--    coach needs answered before prescribing salmon a third time.
--
--    Two columns, on both prescribed_meals and prescribed_sessions: the veto that killed
--    it, and the row that replaced it. Nullable, because the overwhelming majority of
--    prescriptions are neither.
--
-- 2. trigger_type / trigger_id ON plan_versions.
--
--    Both columns have existed since 003 and are NULL on every row in the database.
--    Plans::persist() never wrote them. §3's plan_versions sketch calls trigger_ref "the
--    veto / chat turn / check-in that caused it", and a veto replacement is the first
--    case where the causing row is something the USER can see and ask about. So this
--    backfills nothing (there is nothing to backfill) but the write path starts here.
--
--    Not a schema change — an index, so "what did this veto do to my plan" is a lookup
--    rather than a scan.
-- -------------------------------------------------------------

-- ---- prescribed_meals ---------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prescribed_meals'
      AND COLUMN_NAME = 'vetoed_by_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE prescribed_meals
        -- The veto that rejected this meal. Set on the OLD row, in the superseded
        -- version: this is the tombstone, not a property of the replacement.
        ADD COLUMN vetoed_by_id BIGINT UNSIGNED NULL AFTER sort_order,

        -- The meal that took its place, in the new version. Nullable even when
        -- vetoed_by_id is set: a declined veto marks nothing, and an accepted one whose
        -- regeneration failed must not point at a replacement that does not exist.
        ADD COLUMN replaced_by_id BIGINT UNSIGNED NULL AFTER vetoed_by_id,

        ADD CONSTRAINT fk_pmeal_vetoed_by FOREIGN KEY (vetoed_by_id)
            REFERENCES vetoes(id) ON DELETE SET NULL,

        -- SET NULL rather than CASCADE: losing the replacement must not delete the
        -- record of what was refused. The tombstone outlives the substitute.
        ADD CONSTRAINT fk_pmeal_replaced_by FOREIGN KEY (replaced_by_id)
            REFERENCES prescribed_meals(id) ON DELETE SET NULL',
    'SELECT "014: prescribed_meals.vetoed_by_id already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---- prescribed_sessions ------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prescribed_sessions'
      AND COLUMN_NAME = 'vetoed_by_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE prescribed_sessions
        ADD COLUMN vetoed_by_id BIGINT UNSIGNED NULL AFTER sort_order,
        ADD COLUMN replaced_by_id BIGINT UNSIGNED NULL AFTER vetoed_by_id,

        ADD CONSTRAINT fk_psession_vetoed_by FOREIGN KEY (vetoed_by_id)
            REFERENCES vetoes(id) ON DELETE SET NULL,
        ADD CONSTRAINT fk_psession_replaced_by FOREIGN KEY (replaced_by_id)
            REFERENCES prescribed_sessions(id) ON DELETE SET NULL',
    'SELECT "014: prescribed_sessions.vetoed_by_id already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---- prescribed_exercises -----------------------------------------------------
--
-- vetoes.subject_type includes 'exercise', so a user can refuse one movement without
-- refusing the session around it — "not back squats, my knee" is a different statement
-- from "not legs today", and collapsing them would lose the distinction that makes a
-- replacement sensible.

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prescribed_exercises'
      AND COLUMN_NAME = 'vetoed_by_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE prescribed_exercises
        ADD COLUMN vetoed_by_id BIGINT UNSIGNED NULL AFTER sort_order,
        ADD COLUMN replaced_by_id BIGINT UNSIGNED NULL AFTER vetoed_by_id,

        ADD CONSTRAINT fk_pexercise_vetoed_by FOREIGN KEY (vetoed_by_id)
            REFERENCES vetoes(id) ON DELETE SET NULL,
        ADD CONSTRAINT fk_pexercise_replaced_by FOREIGN KEY (replaced_by_id)
            REFERENCES prescribed_exercises(id) ON DELETE SET NULL',
    'SELECT "014: prescribed_exercises.vetoed_by_id already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---- the veto queue -----------------------------------------------------------
--
-- The route records a veto and returns; cron evaluates it. Same split as chat, for the
-- same reason: an accepted veto regenerates the week, which takes minutes, and no HTTP
-- request should hold that open. This index is the queue read.

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vetoes'
      AND INDEX_NAME = 'idx_veto_pending'
);
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_veto_pending ON vetoes (outcome, created_at)',
    'SELECT "014: idx_veto_pending already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---- plan_versions.trigger_ref lookup -----------------------------------------

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'plan_versions'
      AND INDEX_NAME = 'idx_pv_trigger'
);
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_pv_trigger ON plan_versions (trigger_type, trigger_id)',
    'SELECT "014: idx_pv_trigger already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
