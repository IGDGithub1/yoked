-- -------------------------------------------------------------
-- 021 — what each exercise actually works
--
-- The library has had a `pattern` column since 003, and pattern is a MOVEMENT classification,
-- not an anatomical one. `isolation` is the largest bucket at 241 rows and says nothing about
-- what is being isolated: a bicep curl and a lateral raise are both isolation.
--
-- Four things need this and none of them can be done today:
--
--   PROMPT SIZE. Isolation is ~1,642 tokens, over a third of a gym user's vocabulary, and it is
--   mostly the same movements repeated across implements — 49 bicep curls, 36 raises. Capping
--   it needs an axis to cap along, and the muscle is that axis.
--
--   BALANCE. Nothing can detect a week that hammers quads and ignores hamstrings.
--
--   SORENESS. The daily check-in collects it (§8) and there is no way to connect "sore" to what
--   caused it.
--
--   TELLING THE USER. "What does this work?" is unanswerable, which matters most for the
--   beginners the app is partly for.
--
-- ONE PRIMARY, NOT A LIST. The source data has exactly one primary muscle per exercise across
-- all 1038 rows — never two, never zero for anything that is an exercise rather than a
-- compendium activity. So this is an ENUM rather than JSON: it validates, it indexes, and it
-- cannot drift into seventeen spellings of "glutes".
--
-- SECONDARY IS JSON because it genuinely varies, up to ten per exercise.
--
-- Both NULLABLE. The 165 rows with no muscle data are compendium activities — "Walking the
-- dog", "Billiards" — where the question does not apply. NULL says that honestly; picking a
-- muscle for them would be inventing data.
--
-- CLAUSE ORDER: COMMENT before AFTER. See 016.
-- -------------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'exercises'
      AND COLUMN_NAME = 'primary_muscle'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE exercises
        ADD COLUMN primary_muscle ENUM(
            ''quadriceps'',''hamstrings'',''glutes'',''calves'',''adductors'',''abductors'',
            ''chest'',''lats'',''middle_back'',''lower_back'',''traps'',''shoulders'',
            ''biceps'',''triceps'',''forearms'',''abdominals'',''neck''
        ) NULL
            COMMENT ''The muscle this mainly works. NULL for activities where it does not apply''
            AFTER pattern',
    'SELECT "021: exercises.primary_muscle already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'exercises'
      AND COLUMN_NAME = 'secondary_muscles'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE exercises
        ADD COLUMN secondary_muscles JSON NULL
            COMMENT ''Also worked, as a list. Up to ten; empty and NULL both mean none''
            AFTER primary_muscle',
    'SELECT "021: exercises.secondary_muscles already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Indexed because the vocabulary builder groups by it on every generation, and because balance
-- checks will scan a week's prescriptions by muscle.
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'exercises'
      AND INDEX_NAME = 'idx_exercise_muscle'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE exercises ADD KEY idx_exercise_muscle (primary_muscle, pattern)',
    'SELECT "021: idx_exercise_muscle already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
