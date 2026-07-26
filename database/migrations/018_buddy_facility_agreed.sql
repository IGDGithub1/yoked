-- -------------------------------------------------------------
-- 018 — did the pair actually agree where they are training?
--
-- 016 gave a shared day an access tier and filled it with the MORE RESTRICTIVE of the two
-- users. That was wrong, and wrong in a way that defeats the feature.
--
-- A buddy pair trains in the SAME PLACE. Resolving full_gym against home_gym to home_gym does
-- not describe a place two people can both attend; it describes a capability tier they happen
-- to share, in two different buildings. The pair got a session neither could necessarily go
-- to, while the only question that mattered — whose gym, or whose garage — was never asked.
--
-- It also compromised in one direction only. Pairing could cost you equipment and never gain
-- you any, when in practice the common and better answer is that the person with the gym
-- membership brings the other one along.
--
-- So the tier is now seeded to the MORE CAPABLE of the two, on the assumption that the less
-- equipped user travels, and this column records whether the pair has confirmed that guess.
-- Unconfirmed days are flagged in the app rather than silently trusted.
--
-- WHAT THE APP DOES NOT STORE: an address. Users sort out where to meet between themselves;
-- the app only needs to know what kind of facility to prescribe equipment for.
--
-- Individual days are untouched. BuddySchedule::effective overwrites only the shared weekdays,
-- so a user who visits a full gym on Wednesday still gets their own home_gym on Tuesday.
--
-- CLAUSE ORDER: COMMENT comes BEFORE AFTER. See 016 — the 1064 quotes the comment text and
-- reads like a quoting bug. It is not.
-- -------------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'buddy_schedule_days'
      AND COLUMN_NAME = 'access_agreed'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE buddy_schedule_days
        ADD COLUMN access_agreed TINYINT(1) NOT NULL DEFAULT 0
            COMMENT ''1 once both users confirmed the facility type; 0 means we guessed''
            AFTER access',
    'SELECT "018: buddy_schedule_days.access_agreed already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Existing rows were seeded under the old most-restrictive rule, so their tier is not merely
-- unconfirmed, it is probably wrong. Left as access_agreed = 0 by the DEFAULT above, which is
-- what puts them in front of the users to settle.
