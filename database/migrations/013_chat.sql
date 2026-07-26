-- -------------------------------------------------------------
-- 013 — interjections (SPEC-coaching §6)
--
-- chat_turns has existed since 004 with `outcome` and `resulting_plan_version_id`
-- already on it, because the shape of the conversation was settled before any of it was
-- built. Drift questions (011) write into it and nobody can reply; Next Day Review
-- circumstances (012) get recorded and nothing evaluates them. This is the loop both are
-- waiting on.
--
-- What is missing is small, which is the point:
--
--   1. The link from a turn to the CIRCUMSTANCE it produced. circumstances already has
--      chat_turn_id pointing the other way, but the reverse lookup is what the coach view
--      needs to show "you told me X and here is what I did about it".
--   2. Which plan version a turn was ABOUT, distinct from one it caused. A revision has to
--      be explainable after the fact against the plan it replaced.
-- -------------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'chat_turns'
      AND COLUMN_NAME = 'circumstance_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE chat_turns
        -- The fact this turn recorded, when it recorded one. Set on the USER turn:
        -- the user states a circumstance, and Claude decides what to do about it.
        ADD COLUMN circumstance_id BIGINT UNSIGNED NULL AFTER drift_state,

        -- The plan the turn was evaluated against, distinct from
        -- resulting_plan_version_id which is the one it produced. Both matter: "this
        -- revision replaced that plan because of this message" is the whole audit
        -- trail, and after a supersede the old id is the only way back to what was
        -- actually changed.
        ADD COLUMN context_plan_version_id BIGINT UNSIGNED NULL AFTER circumstance_id,

        ADD CONSTRAINT fk_chat_circumstance FOREIGN KEY (circumstance_id)
            REFERENCES circumstances(id) ON DELETE SET NULL,
        ADD CONSTRAINT fk_chat_context_plan FOREIGN KEY (context_plan_version_id)
            REFERENCES plan_versions(id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

/*
 * A user turn awaiting a reply.
 *
 * The coach view polls "is there an answer yet" while cron does the model call, and the
 * unread-count query runs on every Dashboard load. Both want "the newest turns for this
 * user", which idx_chat_user (user_id, id) already serves — but "which user turns have no
 * assistant reply after them" does not have an index and is asked every sweep.
 *
 * Denormalised rather than derived, because the alternative is a correlated subquery per
 * user per sweep and the answer is known at write time.
 */
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'chat_turns'
      AND COLUMN_NAME = 'answered_at'
);
SET @sql = IF(@col_exists = 0,
    -- Set on a USER turn when the assistant replies to it. NULL on assistant turns
    -- always, which is why this is not simply "has a reply".
    'ALTER TABLE chat_turns
        ADD COLUMN answered_at DATETIME NULL AFTER context_plan_version_id,
        ADD KEY idx_chat_pending (user_id, role, answered_at)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Existing user turns predate the column and have no reply coming. Marking them answered
-- keeps them out of the pending sweep, which would otherwise try to answer conversation
-- history from before the loop existed.
UPDATE chat_turns
   SET answered_at = created_at
 WHERE role = 'user' AND answered_at IS NULL;
