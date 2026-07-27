-- -------------------------------------------------------------
-- 020 — a pattern for jumps
--
-- The library gains box jumps, hurdle hops, cone hops and tuck jumps with the exercise import,
-- and none of the nine existing patterns describes them. `squat` is the closest and it is
-- wrong: a box jump is not a squat, and filing it as one means a "squat" slot in a session
-- skeleton can be filled with a plyometric, which is a different stimulus, a different landing
-- risk, and a different thing to progress.
--
-- It matters more than a taxonomy tidy because the buddy skeleton (SPEC-coaching §10.1) shares
-- the PATTERN between two users and lets each pick their own variant. A pair told "squat" where
-- one does a back squat and the other a depth jump are not training together in any useful
-- sense.
--
-- ENUM ORDER: appended, not inserted. MySQL stores an ENUM as its ordinal, so inserting a value
-- in the middle silently renumbers everything after it and rewrites the meaning of every stored
-- row. Appending is the only safe edit.
--
-- No data changes: nothing is a plyometric until the import says so.
-- -------------------------------------------------------------

SET @has_ply = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'exercises'
      AND COLUMN_NAME = 'pattern'
      AND COLUMN_TYPE LIKE '%plyometric%'
);
SET @sql = IF(@has_ply = 0,
    'ALTER TABLE exercises
        MODIFY COLUMN pattern ENUM(
            ''squat'',''hinge'',''horizontal_push'',''horizontal_pull'',
            ''vertical_push'',''vertical_pull'',''lunge'',''carry'',
            ''anti_rotation'',''anti_extension'',''anti_lateral_flexion'',
            ''flexion'',''extension'',''isolation'',''cardio'',''other'',
            ''plyometric''
        ) NOT NULL',
    'SELECT "020: exercises.pattern already has plyometric"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
