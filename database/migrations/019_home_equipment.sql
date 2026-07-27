-- -------------------------------------------------------------
-- 019 — what is actually in the home gym
--
-- The availability grid lets a user mark a day full_gym, home_gym, bodyweight or outdoors, and
-- PlanSchema::availableAt returned true for every exercise on a home_gym day. So home_gym was a
-- synonym for full_gym: a person with two dumbbells in a spare room was assumed to have a cable
-- tower, a hack squat, a leg press and a pool.
--
-- Nothing caught it. Safety::checkAvailability compares the SESSION location against the day
-- access, not individual exercises against equipment, so a home session full of machine work
-- validates cleanly. The user just gets a plan they cannot perform, and the app never knows.
--
-- The food side already does this properly: food_preferences.kitchen_equipment is a JSON list
-- collected at onboarding and used. This is the training equivalent.
--
-- SIX ITEMS, NOT THIRTY-TWO. The library uses 32 distinct equipment tokens, but 20 of them
-- unlock exactly one exercise each (pool, battle ropes, dowel, foam roller). Measured against
-- the seeded library: bodyweight alone is 19 exercises, dumbbells take it to 29, and dumbbells
-- plus bench, bands and a pull-up bar reach 38 of 90. A short list captures nearly all the
-- value and can be answered without thinking; a 32-item checklist during onboarding is where
-- people tick everything without reading.
--
-- PER USER, NOT PER DAY. A home gym is the same room every day. The four access labels already
-- separate "at home" from "at the gym" from "in a park".
--
-- NULL means unanswered, which is NOT the same as empty. An empty list is "I have nothing,
-- bodyweight only"; NULL is a user who onboarded before this existed, and they keep the old
-- permissive behaviour until they answer rather than silently losing their barbell.
--
-- CLAUSE ORDER: COMMENT before AFTER. See 016 — the 1064 quotes the comment text and reads
-- like a quoting bug. It is not.
-- -------------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'training_preferences'
      AND COLUMN_NAME = 'home_equipment'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE training_preferences
        ADD COLUMN home_equipment JSON NULL
            COMMENT ''Kit available on a home_gym day. NULL = never asked, [] = nothing''
            AFTER known_lifts',
    'SELECT "019: training_preferences.home_equipment already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
