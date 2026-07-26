-- -------------------------------------------------------------
-- 017 — declared buddy absence (SPEC-coaching 10.5)
--
-- "A buddy who travels, gets ill, or unpairs must never leave the other waiting."
--
-- Three cases in the spec, and only two of them need a table. Travel is declared in advance;
-- illness is declared mid-week. Both are a statement that this person will not be training
-- with their buddy for a stretch, so both are one row here.
--
-- The third case, undeclared silence, needs nothing stored: Drift::lastLoggedDate already
-- knows when somebody last logged anything, and the partner generation reads it directly.
-- Persisting a derived fact would be a second source of truth that goes stale.
--
-- WHY user_id AND buddy_pair_id.
--
-- The absence belongs to the PERSON — they are away whoever they are paired with — but it is
-- only meaningful in the context of a pair, and it must disappear when the pair does. Both
-- columns cascade, so unpairing cleans up without a sweep.
--
-- The pair is nullable so a future "I am away" that is not about a buddy at all (pausing
-- coaching for a holiday, say) can reuse this rather than needing a third table.
--
-- CLAUSE ORDER: COMMENT before AFTER. MySQL puts COMMENT inside column_definition and AFTER
-- outside it as a placement clause, and the 1064 quotes the comment text so it reads like a
-- quoting bug. That cost three wrong diagnoses on 016.
-- -------------------------------------------------------------

SET @tbl_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'buddy_absences'
);
SET @sql = IF(@tbl_exists = 0,
    'CREATE TABLE buddy_absences (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id       BIGINT UNSIGNED NOT NULL,
        buddy_pair_id BIGINT UNSIGNED NULL,

        -- Travel is planned and declared before generation; illness is not and gets declared
        -- mid-week. The distinction changes what happens to the partner week that is already
        -- built (10.5), so it is recorded rather than inferred from the dates.
        kind          ENUM(''travel'',''illness'',''other'') NOT NULL DEFAULT ''other'',

        starts_on     DATE NOT NULL,

        -- The day they expect to be back and training. NULL means open-ended, which is honest
        -- for an illness nobody can put a date on, and is treated as "away until they say
        -- otherwise" rather than as forever.
        returns_on    DATE NULL,

        -- Cleared rather than deleted when someone comes back early, so "I was away that week"
        -- survives as an explanation for a quiet stretch.
        cancelled_at  DATETIME NULL,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        -- The lookup generation makes: is this user away across a given week?
        KEY idx_babs_user_window (user_id, starts_on, returns_on),
        KEY idx_babs_pair (buddy_pair_id),
        CONSTRAINT fk_babs_user FOREIGN KEY (user_id)
            REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_babs_pair FOREIGN KEY (buddy_pair_id)
            REFERENCES buddy_pairs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT "017: buddy_absences already present"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
