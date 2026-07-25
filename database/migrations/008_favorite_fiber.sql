-- -------------------------------------------------------------
-- 008 — favorites keep their fiber
--
-- favorite_foods stored carbs_g only, meaning NET, while logged_entries keeps
-- all three of carbs_g / fiber_g / total_carbs_g. So starring a food threw its
-- fiber away permanently: log "67g carbs, 10g fiber", favorite it, and the
-- favorite remembered 57g net and nothing else. Re-logging from it produced an
-- entry with fiber_g NULL where the original had a real figure.
--
-- Fiber is not decoration here. It is the input to net carbs, which is the
-- number the goal evaluator judges, and a training app wants the fiber total in
-- its own right.
--
-- Both columns are added so a favorite ROUND-TRIPS to an identical entry:
-- total_carbs + fiber is the shape addEntry() takes, and with both present the
-- client no longer needs a special case that sends pre-netted carbs.
-- -------------------------------------------------------------

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'favorite_foods'
      AND COLUMN_NAME = 'fiber_g'
);
SET @sql = IF(@col_exists = 0,
    -- NULL, not 0, and the distinction matters: 0 asserts "this food has no
    -- fiber", NULL admits "nobody recorded it". Storing 0 for every existing
    -- favorite would be inventing data, and normaliseMacros() already treats a
    -- missing fiber figure as "total IS net", which is the correct reading of
    -- the rows that already exist.
    'ALTER TABLE favorite_foods
        ADD COLUMN fiber_g       DECIMAL(6,1) NULL AFTER carbs_g,
        ADD COLUMN total_carbs_g DECIMAL(6,1) NULL AFTER fiber_g',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill total_carbs_g from the net value for rows that predate this.
--
-- With fiber unknown, total == net is the only honest reconstruction, and it is
-- exactly what the read path would infer anyway. Doing it here means the column
-- is populated for every row rather than half the table needing a fallback.
-- Guarded on fiber_g IS NULL so a re-run cannot touch rows saved with real data.
UPDATE favorite_foods
   SET total_carbs_g = carbs_g
 WHERE total_carbs_g IS NULL
   AND fiber_g IS NULL;
