-- -------------------------------------------------------------
-- 007 — free-logged sessions
--
-- A session logged WITHOUT a prescription had nowhere to record what kind of
-- training it was. prescribed_session_id has always been nullable (004) and
-- Training::day() already appends unprescribed sessions, but it read the type
-- off the prescription — so a free-logged row reported 'other' no matter what
-- the user actually did.
--
-- That is load-bearing during the baseline fortnight, which is the ONLY source
-- of data the first plan is built from: "three 30-minute runs and no lifting"
-- and "three lifting sessions" have to be distinguishable, and inferring the
-- type from the logged exercises fails for the walk that logs none.
-- -------------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'logged_sessions'
      AND COLUMN_NAME = 'session_type'
);
SET @sql = IF(@col_exists = 0,
    -- Same members as prescribed_sessions.session_type, minus 'rest': you do
    -- not log a rest day as a session, the absence IS the record.
    --
    -- NULL rather than a default, because it means something specific: a row
    -- logged against a prescription takes its type from the plan, and only a
    -- free-logged session carries its own. Defaulting to ''strength'' would
    -- silently claim every pre-migration row was a lifting session.
    'ALTER TABLE logged_sessions
        ADD COLUMN session_type
            ENUM(''strength'',''cardio'',''hybrid'',''mobility'',''active_recovery'')
            NULL AFTER prescribed_session_id',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Free-logging needs to find an exercise from what the user typed, and the
-- library ships 90 exercises plus 53 aliases. Name is what a person types;
-- slug and alias were already indexed (unique keys), name was not.
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'exercises'
      AND INDEX_NAME = 'idx_exercise_name'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE exercises ADD KEY idx_exercise_name (name)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
