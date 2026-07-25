-- =============================================================
-- Migration 003 — exercise library, plan versions, prescriptions
--
-- Implements SPEC-coaching.md §2, §3, §10.
--
-- The load-bearing decision here is §2: a plan is a VERSION, not a mutable
-- row. Three things force it:
--   1. Adherence needs a stable referent. "Did the user follow the plan?" is
--      meaningless if the plan silently changed underneath them.
--   2. Claude needs to see WHY a plan changed mid-week to coach the next one.
--      A mutated row destroys exactly that.
--   3. Chat can change the plan, so changes are frequent — every one must be
--      attributable and revertable.
-- Corollary: prescriptions are never overwritten. A vetoed meal stays in the
-- record, marked vetoed, with its reason and its replacement.
-- =============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -------------------------------------------------------------
-- Exercise library (SPEC-onboarding.md §6 / scoping decision)
--
-- Grows by promotion: Claude proposes an exercise, and once it enters a plan
-- it becomes a library row with a canonical name. This gets the freedom of
-- open-ended programming while keeping a fixed vocabulary — otherwise "DB
-- Bench" and "Dumbbell Bench Press" become different exercises and PR
-- history fragments across both.
-- -------------------------------------------------------------

CREATE TABLE exercises (
    id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Canonical slug. Resolve aliases to this before writing any log row.
    slug VARCHAR(80)  NOT NULL,
    name VARCHAR(120) NOT NULL,
    category ENUM('strength','cardio','core','mobility','activity') NOT NULL,
    -- Movement pattern is what lets a buddy pair share a skeleton while
    -- diverging on the variant (SPEC-coaching.md §10.2).
    pattern ENUM('squat','hinge','horizontal_push','horizontal_pull',
                 'vertical_push','vertical_pull','lunge','carry',
                 'anti_rotation','anti_extension','anti_lateral_flexion',
                 'flexion','extension','isolation','cardio','other') NOT NULL,
    -- What it needs. Validated against the day's availability.access — this
    -- is why "equipment not available" is a hard constraint, not a preference.
    equipment JSON NULL,
    -- Populated by the promotion path, so a proposed variant can inherit its
    -- parent's load history rather than starting cold.
    parent_id BIGINT UNSIGNED NULL,
    demo_url  VARCHAR(500) NULL,
    -- Loaded by weight, bodyweight, timed hold, or distance. Decides which
    -- log columns are meaningful.
    load_type ENUM('weight','bodyweight','assisted','time','distance') NOT NULL DEFAULT 'weight',
    is_system  TINYINT(1) NOT NULL DEFAULT 0,   -- seeded, not user-created
    created_by BIGINT UNSIGNED NULL,            -- NULL = Claude-proposed
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_exercise_slug (slug),
    KEY idx_exercise_pattern (pattern, category),
    CONSTRAINT fk_exercise_parent  FOREIGN KEY (parent_id)  REFERENCES exercises(id) ON DELETE SET NULL,
    CONSTRAINT fk_exercise_creator FOREIGN KEY (created_by) REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aliases collapse into the canonical row. Populated as Claude produces
-- variant names for an exercise already in the library.
CREATE TABLE exercise_aliases (
    alias       VARCHAR(120) NOT NULL,
    exercise_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (alias),
    KEY idx_alias_exercise (exercise_id),
    CONSTRAINT fk_alias_exercise FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Buddy pairs (SPEC-coaching.md §10)
--
-- Requires an accepted friendship. Both sides opt in; either can unpair.
-- Pairing is an enhancement to a complete single-user plan, never a
-- dependency of one (§10.5) — so nothing below is NOT NULL on a pair.
-- -------------------------------------------------------------

CREATE TABLE buddy_pairs (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_lo   BIGINT UNSIGNED NOT NULL,
    user_hi   BIGINT UNSIGNED NOT NULL,
    status    ENUM('pending','active','ended') NOT NULL DEFAULT 'pending',
    requested_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at   DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_buddy_pair (user_lo, user_hi),
    KEY idx_buddy_lo (user_lo, status),
    KEY idx_buddy_hi (user_hi, status),
    CONSTRAINT fk_buddy_lo FOREIGN KEY (user_lo) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_buddy_hi FOREIGN KEY (user_hi) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_buddy_order CHECK (user_lo < user_hi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Plan versions (SPEC-coaching.md §2)
--
-- One row per generated version of a user's week. superseded_at IS NULL
-- identifies the live version. Each logged day is measured against whichever
-- version was live when it was logged.
-- -------------------------------------------------------------

CREATE TABLE plan_versions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    week_start DATE NOT NULL,              -- always a Monday
    version    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    reason     ENUM('initial','provisional','veto','interjection',
                    'drift_adaptation','check_in','emphasis_change') NOT NULL,
    -- What caused this version: a veto id, chat turn id, check-in id.
    trigger_type ENUM('veto','chat_turn','check_in','cron','manual') NULL,
    trigger_id   BIGINT UNSIGNED NULL,
    -- Pair context, when the week was generated as a synced pair (§10).
    buddy_pair_id BIGINT UNSIGNED NULL,
    -- Claude's own summary of the week, in the user's tone.
    summary    TEXT NULL,
    -- Audit of the generation itself: model id, token counts, retry count.
    -- Retries happen when a hard constraint was violated (SPEC-safety.md §5).
    generation_meta JSON NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    superseded_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_plan_user_week_ver (user_id, week_start, version),
    -- The hot lookup: this user's live plan for this week.
    KEY idx_plan_live (user_id, week_start, superseded_at),
    KEY idx_plan_pair (buddy_pair_id),
    CONSTRAINT fk_plan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_pair FOREIGN KEY (buddy_pair_id)
        REFERENCES buddy_pairs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Prescribed sessions (SPEC-coaching.md §3.3)
--
-- is_committed is the §3.3a distinction, and it is not cosmetic: adherence
-- counts committed sessions only. A user who states 5 days and completes 5
-- has a perfect week even if two optional sessions went untouched. Showing
-- them 5/7 would manufacture a failure they never agreed to.
-- -------------------------------------------------------------

CREATE TABLE prescribed_sessions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_version_id BIGINT UNSIGNED NOT NULL,
    session_date    DATE NOT NULL,
    session_type    ENUM('strength','cardio','hybrid','mobility','active_recovery','rest') NOT NULL,
    focus           ENUM('upper','lower','full','push','pull','core','conditioning','none')
                    NOT NULL DEFAULT 'none',
    -- Sub-focus within the day: 'squat', 'hinge', 'horizontal', 'vertical'.
    focus_detail    VARCHAR(40) NULL,
    -- FALSE = optional. Bonus, never debt (§3.3a).
    is_committed    TINYINT(1) NOT NULL DEFAULT 1,
    target_minutes  SMALLINT UNSIGNED NULL,
    location        ENUM('full_gym','home_gym','bodyweight','outdoors') NULL,

    -- Warm-ups are prescribed, not left to the user (§3.3). Marked required
    -- where a cardiac or joint modifier applies.
    warmup_minutes  TINYINT UNSIGNED NULL,
    warmup_required TINYINT(1) NOT NULL DEFAULT 0,
    warmup_detail   TEXT NULL,

    -- The "why this session" line. Depth per profiles.explanation_depth.
    rationale       TEXT NULL,
    -- Set when this session came from a shared buddy skeleton (§10.1).
    shared_skeleton_key CHAR(36) NULL,
    sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_psession_plan (plan_version_id, session_date),
    KEY idx_psession_skeleton (shared_skeleton_key),
    CONSTRAINT fk_psession_plan FOREIGN KEY (plan_version_id)
        REFERENCES plan_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Prescribed exercises
--
-- block distinguishes the main work from the core block, which matters
-- because §3.3b places core AFTER the main work (a fatigued core before
-- heavy squats is a form risk) and because for buddy pairs the core block is
-- identical while the main work diverges freely (§10.2a).
-- -------------------------------------------------------------

CREATE TABLE prescribed_exercises (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id  BIGINT UNSIGNED NOT NULL,
    exercise_id BIGINT UNSIGNED NOT NULL,
    block       ENUM('warmup','main','core','cooldown') NOT NULL DEFAULT 'main',
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,

    sets        TINYINT UNSIGNED NULL,
    -- Text, not int: "8", "8-10", "12/side", "AMRAP" are all real.
    target_reps VARCHAR(20) NULL,
    target_weight_kg DECIMAL(6,2) NULL,   -- canonical metric; UI converts
    -- Per-side dumbbell work: prescription reads "2 x 20 lb".
    is_per_side TINYINT(1) NOT NULL DEFAULT 0,
    target_seconds SMALLINT UNSIGNED NULL,   -- timed holds
    target_distance_m SMALLINT UNSIGNED NULL,
    target_rpe  TINYINT UNSIGNED NULL,
    rest_seconds SMALLINT UNSIGNED NULL,

    -- Cardio blocks: {"modality":"recumbent_bike","minutes":25,"intervals":...}
    cardio_detail JSON NULL,

    -- Per-exercise "why", for substitutions that would otherwise look
    -- arbitrary (§3.3): "trap bar rather than straight bar; at 6'4" the
    -- higher neutral handle is kinder to your lower back."
    rationale   VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_pexercise_session (session_id, block, sort_order),
    KEY idx_pexercise_exercise (exercise_id),
    CONSTRAINT fk_pexercise_session  FOREIGN KEY (session_id)
        REFERENCES prescribed_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_pexercise_exercise FOREIGN KEY (exercise_id)
        REFERENCES exercises(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Prescribed nutrition (SPEC-coaching.md §3.4)
--
-- Daily macro targets are per-DAY, not per-week: training days and rest days
-- differ. Targets live on their own row so a day can exist with targets and
-- no menu (the 'targets_and_options' structure setting).
-- -------------------------------------------------------------

CREATE TABLE prescribed_days (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_version_id BIGINT UNSIGNED NOT NULL,
    day_date        DATE NOT NULL,
    target_calories SMALLINT UNSIGNED NOT NULL,
    target_protein_g DECIMAL(6,1) NOT NULL,
    target_fat_g     DECIMAL(6,1) NOT NULL,
    target_carbs_g   DECIMAL(6,1) NOT NULL,
    -- Per-day goal constraints, in the SPEC-targets.md vocabulary carried
    -- over from Keto Tracker:
    --   {"protein":{"mode":"at_least"},
    --    "calories":{"mode":"range_pct","lo":0.85,"hi":1.05}}
    -- This is where the "calories short but protein on target = adherent"
    -- rule lives (SPEC-safety.md §8) — a generous lo bound, not special-case
    -- code.
    constraints     JSON NOT NULL,
    notes           TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pday_plan_date (plan_version_id, day_date),
    CONSTRAINT fk_pday_plan FOREIGN KEY (plan_version_id)
        REFERENCES plan_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prescribed_meals (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    prescribed_day_id BIGINT UNSIGNED NOT NULL,
    slot       ENUM('breakfast','lunch','dinner','snack_am','snack_pm','snack_eve') NOT NULL,
    -- 'specified' = a full recipe. 'target_only' = macros, user chooses.
    -- 'unplanned' = deliberately left free (the negotiated eat-out count).
    kind       ENUM('specified','target_only','unplanned') NOT NULL DEFAULT 'specified',
    name       VARCHAR(200) NULL,
    -- Structured, not prose. NON-NEGOTIABLE: SPEC-safety.md §5 validates
    -- every ingredient against hard food constraints, and prose cannot be
    -- validated. The same structure makes "ate as planned" a one-tap log,
    -- which is the biggest adherence lever in the app.
    -- [{"item":"chicken breast","grams":170,"note":"..."}, ...]
    ingredients JSON NULL,
    method     TEXT NULL,
    prep_minutes TINYINT UNSIGNED NULL,
    calories   SMALLINT UNSIGNED NULL,
    protein_g  DECIMAL(6,1) NULL,
    fat_g      DECIMAL(6,1) NULL,
    carbs_g    DECIMAL(6,1) NULL,   -- NET carbs, per SPEC-nutrition.md §2
    fiber_g    DECIMAL(6,1) NULL,
    -- target_only slots: what the user should aim for.
    target_note VARCHAR(300) NULL,
    -- Alternatives offered alongside, for the 'targets_and_options' setting.
    suggestions JSON NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pmeal_day_slot (prescribed_day_id, slot),
    CONSTRAINT fk_pmeal_day FOREIGN KEY (prescribed_day_id)
        REFERENCES prescribed_days(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
