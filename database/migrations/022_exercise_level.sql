-- -------------------------------------------------------------
-- 022 — how hard an exercise is to perform
--
-- A beginner should not be handed an expert movement. The library had no way to say which was
-- which, so the only guard was the model's own judgement from the user's stated experience —
-- which works until it does not, and the failure mode is somebody attempting a renegade row in
-- week one.
--
-- It also gives §7.3's "grow and change" an axis it was missing. Rotating between exercises of
-- equal difficulty is variety; moving from a goblet squat to a front squat as somebody gets
-- stronger is PROGRESSION, and the app could not tell those apart.
--
-- THE DATA WAS ALREADY THERE. exercises_categorized.json carries a `level` on every row and the
-- import read nothing from it: 523 beginner, 293 intermediate, 57 expert, 165 blank. The blanks
-- are activities and cardio entries, where difficulty is not a property of the thing — "Walking
-- the dog" has no skill floor.
--
-- NULLABLE for that reason, and because our own 90 originals predate the field. NULL means
-- unknown rather than easy, so nothing keys a safety decision off its absence.
--
-- CLAUSE ORDER: COMMENT before AFTER. See 016.
-- -------------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'exercises'
      AND COLUMN_NAME = 'level'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE exercises
        ADD COLUMN level ENUM(''beginner'',''intermediate'',''expert'') NULL
            COMMENT ''Skill floor. NULL where difficulty does not apply, e.g. an activity''
            AFTER load_type',
    'SELECT "022: exercises.level already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
