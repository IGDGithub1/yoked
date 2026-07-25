-- =============================================================
-- Migration 005 — seed data: exercise library + goal presets
--
-- The exercise library is seeded rather than left empty because generation
-- needs a vocabulary to pick from on day one. It still GROWS by promotion
-- (SPEC-coaching.md): Claude proposes an exercise, and accepted proposals
-- become rows with a canonical slug. This is the starting set, not a ceiling.
--
-- Equipment reflects the full gym both first users have access to. The
-- equipment JSON is matched against the day's availability.access, which is
-- why "equipment not available" is a hard constraint rather than a
-- preference — a barbell squat on a bodyweight-only Saturday is not a
-- suggestion the user can be talked into.
--
-- Aliases matter more than they look: without them "DB Bench" and "Dumbbell
-- Bench Press" become separate exercises and load history fragments across
-- both, which breaks progression.
-- =============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -------------------------------------------------------------
-- STRENGTH — squat pattern
-- -------------------------------------------------------------

INSERT IGNORE INTO exercises (slug, name, category, pattern, equipment, load_type, is_system) VALUES
('back-squat',          'Back Squat',              'strength', 'squat', '["barbell","rack"]',        'weight',     1),
('front-squat',         'Front Squat',             'strength', 'squat', '["barbell","rack"]',        'weight',     1),
('goblet-squat',        'Goblet Squat',            'strength', 'squat', '["dumbbell"]',              'weight',     1),
('hack-squat',          'Hack Squat',              'strength', 'squat', '["hack_squat"]',            'weight',     1),
('leg-press',           'Leg Press',               'strength', 'squat', '["leg_press"]',             'weight',     1),
('smith-squat',         'Smith Machine Squat',     'strength', 'squat', '["smith_machine"]',         'weight',     1),
('box-squat',           'Box Squat',               'strength', 'squat', '["barbell","rack","bench"]', 'weight',    1),
('bodyweight-squat',    'Bodyweight Squat',        'strength', 'squat', '[]',                        'bodyweight', 1),
('leg-extension',       'Leg Extension',           'strength', 'isolation', '["selectorized"]',      'weight',     1);

-- -------------------------------------------------------------
-- STRENGTH — hinge pattern
-- -------------------------------------------------------------

INSERT IGNORE INTO exercises (slug, name, category, pattern, equipment, load_type, is_system) VALUES
('conventional-deadlift', 'Conventional Deadlift',  'strength', 'hinge', '["barbell"]',           'weight',     1),
('trap-bar-deadlift',     'Trap Bar Deadlift',      'strength', 'hinge', '["trap_bar"]',          'weight',     1),
('romanian-deadlift',     'Romanian Deadlift',      'strength', 'hinge', '["barbell"]',           'weight',     1),
('db-romanian-deadlift',  'Dumbbell Romanian Deadlift', 'strength', 'hinge', '["dumbbell"]',      'weight',     1),
('barbell-hip-thrust',    'Barbell Hip Thrust',     'strength', 'hinge', '["barbell","bench"]',   'weight',     1),
('machine-hip-thrust',    'Machine Hip Thrust',     'strength', 'hinge', '["plate_loaded"]',      'weight',     1),
('glute-bridge',          'Glute Bridge',           'strength', 'hinge', '[]',                    'bodyweight', 1),
('leg-curl',              'Leg Curl',               'strength', 'isolation', '["selectorized"]',  'weight',     1),
('back-extension',        'Back Extension',         'strength', 'extension', '["back_ext_bench"]', 'bodyweight', 1),
('kettlebell-swing',      'Kettlebell Swing',       'strength', 'hinge', '["kettlebell"]',        'weight',     1);

-- -------------------------------------------------------------
-- STRENGTH — lunge / single leg
-- -------------------------------------------------------------

INSERT IGNORE INTO exercises (slug, name, category, pattern, equipment, load_type, is_system) VALUES
('bulgarian-split-squat', 'Bulgarian Split Squat', 'strength', 'lunge', '["dumbbell","bench"]', 'weight',     1),
('walking-lunge',         'Walking Lunge',         'strength', 'lunge', '["dumbbell"]',         'weight',     1),
('reverse-lunge',         'Reverse Lunge',         'strength', 'lunge', '["dumbbell"]',         'weight',     1),
('step-up',               'Step Up',               'strength', 'lunge', '["dumbbell","box"]',   'weight',     1),
('hip-abduction',         'Hip Abduction',         'strength', 'isolation', '["selectorized"]', 'weight',     1);

-- -------------------------------------------------------------
-- STRENGTH — horizontal push / pull
-- -------------------------------------------------------------

INSERT IGNORE INTO exercises (slug, name, category, pattern, equipment, load_type, is_system) VALUES
('barbell-bench-press',   'Barbell Bench Press',    'strength', 'horizontal_push', '["barbell","bench","rack"]', 'weight', 1),
('db-bench-press',        'Dumbbell Bench Press',   'strength', 'horizontal_push', '["dumbbell","bench"]',       'weight', 1),
('incline-db-press',      'Incline Dumbbell Press', 'strength', 'horizontal_push', '["dumbbell","incline_bench"]', 'weight', 1),
('machine-chest-press',   'Machine Chest Press',    'strength', 'horizontal_push', '["selectorized"]',           'weight', 1),
('smith-bench-press',     'Smith Machine Bench Press', 'strength', 'horizontal_push', '["smith_machine","bench"]', 'weight', 1),
('push-up',               'Push-Up',                'strength', 'horizontal_push', '[]',                         'bodyweight', 1),
('cable-fly',             'Cable Fly',              'strength', 'isolation',        '["cable_tower"]',            'weight', 1),
('seated-cable-row',      'Seated Cable Row',       'strength', 'horizontal_pull',  '["cable_tower"]',            'weight', 1),
('chest-supported-row',   'Chest-Supported Row',    'strength', 'horizontal_pull',  '["selectorized"]',           'weight', 1),
('barbell-row',           'Barbell Row',            'strength', 'horizontal_pull',  '["barbell"]',                'weight', 1),
('db-row',                'Dumbbell Row',           'strength', 'horizontal_pull',  '["dumbbell","bench"]',       'weight', 1),
('trx-row',               'TRX Row',                'strength', 'horizontal_pull',  '["trx"]',                    'bodyweight', 1);

-- -------------------------------------------------------------
-- STRENGTH — vertical push / pull
-- -------------------------------------------------------------

INSERT IGNORE INTO exercises (slug, name, category, pattern, equipment, load_type, is_system) VALUES
('overhead-press',        'Overhead Press',           'strength', 'vertical_push', '["barbell","rack"]',   'weight',   1),
('db-shoulder-press',     'Seated Dumbbell Shoulder Press', 'strength', 'vertical_push', '["dumbbell","bench"]', 'weight', 1),
-- Machine variant matters: it allows pressing without bracing hard against a
-- held breath, which is the hypertension caution in SPEC-safety.md §3.
('machine-shoulder-press','Machine Shoulder Press',   'strength', 'vertical_push', '["selectorized"]',     'weight',   1),
('lat-pulldown',          'Lat Pulldown',             'strength', 'vertical_pull', '["cable_tower"]',      'weight',   1),
('pull-up',               'Pull-Up',                  'strength', 'vertical_pull', '["pull_up_bar"]',      'bodyweight', 1),
('assisted-pull-up',      'Assisted Pull-Up',         'strength', 'vertical_pull', '["assisted_machine"]', 'assisted', 1),
('cable-lateral-raise',   'Cable Lateral Raise',      'strength', 'isolation',     '["cable_tower"]',      'weight',   1),
('db-lateral-raise',      'Dumbbell Lateral Raise',   'strength', 'isolation',     '["dumbbell"]',         'weight',   1),
('face-pull',             'Face Pull',                'strength', 'isolation',     '["cable_tower"]',      'weight',   1);

-- -------------------------------------------------------------
-- STRENGTH — arms / calves
-- -------------------------------------------------------------

INSERT IGNORE INTO exercises (slug, name, category, pattern, equipment, load_type, is_system) VALUES
('cable-curl',            'Cable Curl',            'strength', 'isolation', '["cable_tower"]',   'weight', 1),
('db-curl',               'Dumbbell Curl',         'strength', 'isolation', '["dumbbell"]',      'weight', 1),
('hammer-curl',           'Hammer Curl',           'strength', 'isolation', '["dumbbell"]',      'weight', 1),
('rope-pushdown',         'Rope Pushdown',         'strength', 'isolation', '["cable_tower"]',   'weight', 1),
('overhead-extension',    'Overhead Triceps Extension', 'strength', 'isolation', '["dumbbell"]', 'weight', 1),
('standing-calf-raise',   'Standing Calf Raise',   'strength', 'isolation', '["plate_loaded"]',  'weight', 1),
('seated-calf-raise',     'Seated Calf Raise',     'strength', 'isolation', '["plate_loaded"]',  'weight', 1);

-- -------------------------------------------------------------
-- CORE
--
-- Split by pattern so §3.3b can match the block to the day's focus:
-- lower days get anti_rotation + extension, upper days get anti_extension
-- + flexion. Mostly bodyweight/isometric, which is exactly why a buddy pair
-- can share the core block identically (§10.2a) — no loading problem forces
-- the prescriptions apart.
-- -------------------------------------------------------------

INSERT IGNORE INTO exercises (slug, name, category, pattern, equipment, load_type, is_system) VALUES
('plank',              'Plank',                'core', 'anti_extension',       '[]',               'time',       1),
('side-plank',         'Side Plank',           'core', 'anti_lateral_flexion', '[]',               'time',       1),
('dead-bug',           'Dead Bug',             'core', 'anti_extension',       '[]',               'bodyweight', 1),
('bird-dog',           'Bird Dog',             'core', 'anti_rotation',        '[]',               'bodyweight', 1),
('pallof-press',       'Pallof Press',         'core', 'anti_rotation',        '["cable_tower"]',  'weight',     1),
('cable-woodchop',     'Cable Woodchop',       'core', 'anti_rotation',        '["cable_tower"]',  'weight',     1),
('hanging-knee-raise', 'Hanging Knee Raise',   'core', 'flexion',              '["pull_up_bar"]',  'bodyweight', 1),
('cable-crunch',       'Cable Crunch',         'core', 'flexion',              '["cable_tower"]',  'weight',     1),
('russian-twist',      'Russian Twist',        'core', 'anti_rotation',        '["medicine_ball"]', 'weight',    1),
('suitcase-carry',     'Suitcase Carry',       'core', 'carry',                '["dumbbell"]',     'distance',   1),
('farmer-carry',       'Farmer Carry',         'core', 'carry',                '["dumbbell"]',     'distance',   1),
('overhead-plate-hold','Overhead Plate Hold',  'core', 'anti_extension',       '["plate"]',        'time',       1),
('ab-wheel',           'Ab Wheel Rollout',     'core', 'anti_extension',       '["ab_wheel"]',     'bodyweight', 1);

-- -------------------------------------------------------------
-- CARDIO
--
-- cardio_willing / cardio_refused in training_preferences reference these
-- slugs. Refused cardio is a soft constraint that behaves almost like hard:
-- prescribing it is the fastest way to lose adherence (SPEC-onboarding §6.9).
-- -------------------------------------------------------------

INSERT IGNORE INTO exercises (slug, name, category, pattern, equipment, load_type, is_system) VALUES
('treadmill',       'Treadmill',        'cardio', 'cardio', '["treadmill"]',   'time', 1),
('elliptical',      'Elliptical',       'cardio', 'cardio', '["elliptical"]',  'time', 1),
('rower',           'Rowing Machine',   'cardio', 'cardio', '["rower"]',       'time', 1),
('upright-bike',    'Upright Bike',     'cardio', 'cardio', '["upright_bike"]', 'time', 1),
('recumbent-bike',  'Recumbent Bike',   'cardio', 'cardio', '["recumbent_bike"]', 'time', 1),
('stair-machine',   'Stair Machine',    'cardio', 'cardio', '["stair_machine"]', 'time', 1),
('battle-ropes',    'Battle Ropes',     'cardio', 'cardio', '["battle_ropes"]', 'time', 1),
('walking',         'Walking',          'cardio', 'cardio', '[]',              'time', 1),
('running',         'Running',          'cardio', 'cardio', '[]',              'time', 1),
('swimming',        'Swimming',         'cardio', 'cardio', '["pool"]',        'time', 1);

-- Outdoor activities. Weather-dependent, which is why "thunderstorms on
-- hiking day" is a legitimate veto reason (SPEC-coaching.md §5.2).
INSERT IGNORE INTO exercises (slug, name, category, pattern, equipment, load_type, is_system) VALUES
('hiking',      'Hiking',      'activity', 'cardio', '[]', 'time', 1),
('pickleball',  'Pickleball',  'activity', 'cardio', '[]', 'time', 1),
('tennis',      'Tennis',      'activity', 'cardio', '[]', 'time', 1),
('cycling',     'Cycling',     'activity', 'cardio', '[]', 'time', 1),
('skiing',      'Skiing',      'activity', 'cardio', '[]', 'time', 1),
('fitness-class','Fitness Class', 'activity', 'cardio', '[]', 'time', 1);

-- -------------------------------------------------------------
-- MOBILITY
-- -------------------------------------------------------------

INSERT IGNORE INTO exercises (slug, name, category, pattern, equipment, load_type, is_system) VALUES
('hip-flexor-stretch',  'Hip Flexor Stretch',  'mobility', 'other', '[]',                'time',       1),
('hamstring-stretch',   'Hamstring Stretch',   'mobility', 'other', '[]',                'time',       1),
('leg-swings',          'Leg Swings',          'mobility', 'other', '[]',                'bodyweight', 1),
('band-pull-apart',     'Band Pull-Apart',     'mobility', 'other', '["resistance_band"]', 'bodyweight', 1),
('band-dislocate',      'Band Shoulder Dislocate', 'mobility', 'other', '["resistance_band"]', 'bodyweight', 1),
('banded-glute-walk',   'Banded Glute Walk',   'mobility', 'other', '["resistance_band"]', 'bodyweight', 1),
('hip-hinge-drill',     'Hip Hinge Drill',     'mobility', 'other', '["dowel"]',         'bodyweight', 1),
('scap-shrug',          'Scapular Shrug',      'mobility', 'other', '[]',                'bodyweight', 1),
('foam-roll',           'Foam Rolling',        'mobility', 'other', '["foam_roller"]',   'time',       1);

-- -------------------------------------------------------------
-- ALIASES
--
-- Every name Claude might plausibly produce for an exercise already in the
-- library, mapped to the canonical row. Without these, load history
-- fragments and progression breaks.
-- -------------------------------------------------------------

INSERT IGNORE INTO exercise_aliases (alias, exercise_id)
SELECT a.alias, e.id FROM exercises e JOIN (
    SELECT 'DB Bench'                AS alias, 'db-bench-press'        AS slug UNION ALL
    SELECT 'Dumbbell Bench',              'db-bench-press'        UNION ALL
    SELECT 'DB Bench Press',              'db-bench-press'        UNION ALL
    SELECT 'Flat Dumbbell Press',         'db-bench-press'        UNION ALL
    SELECT 'Bench Press',                 'barbell-bench-press'   UNION ALL
    SELECT 'Flat Bench Press',            'barbell-bench-press'   UNION ALL
    SELECT 'Squat',                       'back-squat'            UNION ALL
    SELECT 'Barbell Squat',               'back-squat'            UNION ALL
    SELECT 'Deadlift',                    'conventional-deadlift' UNION ALL
    SELECT 'Trap Bar DL',                 'trap-bar-deadlift'     UNION ALL
    SELECT 'Hex Bar Deadlift',            'trap-bar-deadlift'     UNION ALL
    SELECT 'RDL',                         'romanian-deadlift'     UNION ALL
    SELECT 'DB RDL',                      'db-romanian-deadlift'  UNION ALL
    SELECT 'Dumbbell RDL',                'db-romanian-deadlift'  UNION ALL
    SELECT 'Hip Thrust',                  'barbell-hip-thrust'    UNION ALL
    SELECT 'Glute Thrust',                'barbell-hip-thrust'    UNION ALL
    SELECT 'OHP',                         'overhead-press'        UNION ALL
    SELECT 'Military Press',              'overhead-press'        UNION ALL
    SELECT 'Standing Press',              'overhead-press'        UNION ALL
    SELECT 'Shoulder Press',              'db-shoulder-press'     UNION ALL
    SELECT 'Seated DB Press',             'db-shoulder-press'     UNION ALL
    SELECT 'Pulldown',                    'lat-pulldown'          UNION ALL
    SELECT 'Cable Pulldown',              'lat-pulldown'          UNION ALL
    SELECT 'Chin-Up',                     'pull-up'               UNION ALL
    SELECT 'Cable Row',                   'seated-cable-row'      UNION ALL
    SELECT 'Machine Row',                 'chest-supported-row'   UNION ALL
    SELECT 'Bent Over Row',               'barbell-row'           UNION ALL
    SELECT 'One Arm Row',                 'db-row'                UNION ALL
    SELECT 'Bulgarian Split Squats',      'bulgarian-split-squat' UNION ALL
    SELECT 'BSS',                         'bulgarian-split-squat' UNION ALL
    SELECT 'Split Squat',                 'bulgarian-split-squat' UNION ALL
    SELECT 'Lateral Raise',               'db-lateral-raise'      UNION ALL
    SELECT 'Side Raise',                  'db-lateral-raise'      UNION ALL
    SELECT 'Bicep Curl',                  'db-curl'               UNION ALL
    SELECT 'Tricep Pushdown',             'rope-pushdown'         UNION ALL
    SELECT 'Triceps Pushdown',            'rope-pushdown'         UNION ALL
    SELECT 'Calf Raise',                  'standing-calf-raise'   UNION ALL
    SELECT 'Woodchop',                    'cable-woodchop'        UNION ALL
    SELECT 'Wood Chop',                   'cable-woodchop'        UNION ALL
    SELECT 'Hanging Leg Raise',           'hanging-knee-raise'    UNION ALL
    SELECT 'Knee Raise',                  'hanging-knee-raise'    UNION ALL
    SELECT 'Farmers Walk',                'farmer-carry'          UNION ALL
    SELECT 'Farmer Walk',                 'farmer-carry'          UNION ALL
    SELECT 'Loaded Carry',                'farmer-carry'          UNION ALL
    SELECT 'Stationary Bike',             'upright-bike'          UNION ALL
    SELECT 'Exercise Bike',               'upright-bike'          UNION ALL
    SELECT 'Stairmaster',                 'stair-machine'         UNION ALL
    SELECT 'StairMaster',                 'stair-machine'         UNION ALL
    SELECT 'Erg',                         'rower'                 UNION ALL
    SELECT 'Concept2',                    'rower'                 UNION ALL
    SELECT 'Jog',                         'running'               UNION ALL
    SELECT 'Jogging',                     'running'               UNION ALL
    SELECT 'Hike',                        'hiking'                UNION ALL
    SELECT 'Leg Press Machine',           'leg-press'
) AS a ON a.slug = e.slug;

-- -------------------------------------------------------------
-- GOAL PRESETS
--
-- The pluggable evaluator replacing Keto Tracker's hardcoded rule. Each
-- preset is a per-macro constraint set in the vocabulary from
-- keto-extract/specs/SPEC-targets.md:
--
--   at_least    value >= target
--   at_most     value <  target (strict)
--   within_pct  target*(1-p) <= value <= target*(1+p)
--   range_pct   target*lo <= value <= target*hi
--   ignore      not scored
--
-- The asymmetry is the point: each macro has a different comparison shape,
-- which is exactly why a single "within X%" rule cannot replace it.
--
-- Stored on goal_presets rather than hardcoded, because the whole reason the
-- keto app couldn't become a training app was that its rule lived in four
-- places in code.
-- -------------------------------------------------------------

-- IF NOT EXISTS because MySQL DDL is not transactional: if a later statement
-- in this file fails, the migration is not recorded and the whole file re-runs.
-- Every statement here is therefore written to be safely repeatable.
CREATE TABLE IF NOT EXISTS goal_presets (
    id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug    VARCHAR(40)  NOT NULL,
    name    VARCHAR(80)  NOT NULL,
    -- Which primary_goal values this preset suits. Advisory: Claude picks,
    -- and the user's assigned preset always wins.
    suits   JSON NULL,
    constraints JSON NOT NULL,
    notes   VARCHAR(500) NULL,
    is_system  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_goalpreset_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO goal_presets (slug, name, suits, constraints, notes) VALUES

('cut', 'Cut', '["lose_fat"]',
 '{"protein":{"mode":"at_least"},
   "calories":{"mode":"range_pct","lo":0.85,"hi":1.00},
   "fat":{"mode":"ignore"},
   "carbs":{"mode":"ignore"}}',
 'Protein at or above target, calories in a deficit band. Fat and carbs unscored — how the calories are composed matters less than hitting protein while under.'),

('recomp', 'Recomp', '["recomp","build_muscle"]',
 '{"protein":{"mode":"at_least"},
   "calories":{"mode":"range_pct","lo":0.90,"hi":1.05},
   "fat":{"mode":"ignore"},
   "carbs":{"mode":"ignore"}}',
 'Protein-led, calories near maintenance with a little room either side.'),

-- The lo bound of 0.80 is deliberate and is the User #2 rule from
-- SPEC-safety.md §8: coming up from a very low intake, a day where protein
-- lands but calories fall short is adherent WITH A NOTE, not a failure.
-- It needs no special-case code — it is just a generous lower bound.
('recomp-building-intake', 'Recomp (building intake)', '["recomp"]',
 '{"protein":{"mode":"at_least"},
   "calories":{"mode":"range_pct","lo":0.80,"hi":1.05},
   "fat":{"mode":"ignore"},
   "carbs":{"mode":"ignore"}}',
 'For someone building intake up from a low base. Wide lower bound so an honest attempt that falls short still counts, provided protein lands.'),

('bulk', 'Bulk', '["build_muscle"]',
 '{"protein":{"mode":"at_least"},
   "calories":{"mode":"at_least"},
   "fat":{"mode":"ignore"},
   "carbs":{"mode":"ignore"}}',
 'Protein and calories both at or above target.'),

('performance', 'Performance', '["improve_cardio","improve_strength"]',
 '{"protein":{"mode":"at_least"},
   "carbs":{"mode":"at_least"},
   "calories":{"mode":"within_pct","pct":0.10},
   "fat":{"mode":"ignore"}}',
 'Carbs matter as fuel, so they are scored at-or-above rather than ignored.'),

('lower-carb', 'Lower Carb', '["lose_fat","general_health"]',
 '{"protein":{"mode":"at_least"},
   "carbs":{"mode":"at_most"},
   "calories":{"mode":"range_pct","lo":0.85,"hi":1.05},
   "fat":{"mode":"ignore"}}',
 'Carbs strictly under target. Useful where carb distribution matters — e.g. the type 2 diabetes modifier in SPEC-safety.md §3.'),

('keto', 'Keto', '["lose_fat"]',
 '{"protein":{"mode":"at_least"},
   "fat":{"mode":"within_pct","pct":0.05},
   "carbs":{"mode":"at_most"},
   "calories":{"mode":"range_pct","lo":0.90,"hi":1.00}}',
 'The original Keto Tracker rule, preserved exactly — now as data rather than hardcoded in four files. Kept as proof the evaluator generalises: if it cannot express keto, it has lost something.'),

('general-health', 'General Health', '["general_health"]',
 '{"protein":{"mode":"at_least"},
   "calories":{"mode":"within_pct","pct":0.15},
   "fat":{"mode":"ignore"},
   "carbs":{"mode":"ignore"}}',
 'Forgiving band for someone not chasing a body-composition target.');

-- Assign a preset to a user's goal. NULL = fall back to the preset that
-- suits their primary_goal.
--
-- MySQL has no ADD COLUMN IF NOT EXISTS, so this is guarded by hand to keep
-- the file repeatable. Note this is NOT the pattern the keto reference used
-- (PIVOT-NOTES gotcha #3: it ran ALTER TABLE ... ADD COLUMN IF NOT EXISTS on
-- every GET request). This runs once, inside a real migration.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'goals'
      AND COLUMN_NAME = 'goal_preset_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE goals
        ADD COLUMN goal_preset_id BIGINT UNSIGNED NULL AFTER primary_goal,
        ADD KEY idx_goals_preset (goal_preset_id),
        ADD CONSTRAINT fk_goals_preset FOREIGN KEY (goal_preset_id)
            REFERENCES goal_presets(id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
