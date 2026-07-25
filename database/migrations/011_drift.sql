-- -------------------------------------------------------------
-- 011 — drift observation state
--
-- SPEC-coaching §4.2 classifies every user's recent days into on_track / minor /
-- significant / absent, and only the last two produce anything. That classification
-- is pure SQL over columns logged_days already caches, so there is nothing to store
-- about the ASSESSMENT itself.
--
-- What does need storing is what was DONE about it. Without that, a cron sweeping
-- every 15 minutes re-asks the same question 96 times a day, and the escalation
-- ladder has no memory of where it is.
--
-- The notifications table (001) already handles nudge delivery and has been unused
-- since it was written; Notify now writes to it. What is missing is the drift
-- question, which lives on chat_turns as an assistant turn with outcome='question'
-- and needs a marker saying which drift episode prompted it.
-- -------------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'chat_turns'
      AND COLUMN_NAME = 'drift_state'
);
SET @sql = IF(@col_exists = 0,
    -- Set only on assistant turns the DRIFT SWEEP opened, so a re-ask can be
    -- suppressed while the same rough patch continues. NULL for ordinary chat,
    -- which is the overwhelming majority of turns.
    --
    -- 'minor' is in the enum but never written by the sweep: §4.2 is explicit that
    -- minor drift aggregates for the weekly check-in rather than generating anything.
    -- It is here so a future caller does not have to migrate to use it.
    'ALTER TABLE chat_turns
        ADD COLUMN drift_state ENUM(''minor'',''significant'',''absent'') NULL
            AFTER outcome',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- "Has the coach already asked about this patch?" runs on every sweep for every
-- user, so it is indexed rather than scanning a growing chat history.
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'chat_turns'
      AND INDEX_NAME = 'idx_chat_drift'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE chat_turns ADD KEY idx_chat_drift (user_id, drift_state, created_at)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
