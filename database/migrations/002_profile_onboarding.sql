-- =============================================================
-- Migration 002 — profile, onboarding, goals, safety constraints
--
-- Implements SPEC-onboarding.md and SPEC-safety.md.
--
-- Design note on the split between columns and JSON:
--   Anything code reads, filters, or validates against gets a column.
--   Anything only Claude reads as context stays in JSON. Free-text answers
--   ("what does success look like?") are never parsed, so a column buys
--   nothing; the weekly availability grid is queried on every generation,
--   so it gets real rows.
-- =============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -------------------------------------------------------------
-- Profile — the stable facts (SPEC-onboarding.md §1, §8, §9)
-- -------------------------------------------------------------

CREATE TABLE profiles (
    user_id       BIGINT UNSIGNED NOT NULL,
    date_of_birth DATE NULL,
    -- Asked for BMR/TDEE estimation only; the UI states that reason (§1.2).
    birth_sex     ENUM('male','female') NULL,
    height_cm     DECIMAL(5,1) NULL,          -- canonical metric; UI converts
    units         ENUM('imperial','metric') NOT NULL DEFAULT 'imperial',

    -- §9 coaching style
    tone          ENUM('sarcastic_hardass','high_school_coach','motivational_speaker',
                       'funny_positive','friendly_encouraging','direct_no_fluff')
                  NOT NULL DEFAULT 'friendly_encouraging',
    nudge_intensity ENUM('leave_me_alone','gentle','persistent','relentless')
                    NOT NULL DEFAULT 'gentle',
    nudge_after_days TINYINT UNSIGNED NOT NULL DEFAULT 3,
    explanation_depth ENUM('just_tell_me','brief','explain') NOT NULL DEFAULT 'brief',
    -- Default hidden: sharing a body metric should be a choice, not a discovery.
    hide_photos       TINYINT(1) NOT NULL DEFAULT 1,
    hide_measurements TINYINT(1) NOT NULL DEFAULT 1,

    -- §3.3b — core work is on by default, adjustable, never an onboarding question.
    core_emphasis ENUM('off','light','standard','heavy') NOT NULL DEFAULT 'standard',

    -- §8 daily-life baselines. Stored so daily check-ins can be read as a
    -- delta: "energy: low" is meaningless without knowing the user's normal.
    baseline_sleep_hours   DECIMAL(3,1) NULL,
    baseline_sleep_quality ENUM('poor','fair','good','great') NULL,
    baseline_activity      ENUM('sedentary','light','moderate','very') NULL,
    baseline_stress        ENUM('low','moderate','high','very_high') NULL,
    baseline_energy        ENUM('drained','low','ok','good','high') NULL,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Onboarding answers
--
-- One row per question. Deliberately generic rather than 80 columns:
--   * the quiz is resumable (§ tiering) — partial state is the normal state
--   * questions will change during build; a schema migration per reworded
--     question is not a cost worth paying
--   * most answers are only ever read as a block, to build a prompt
-- Answers that code must act on are ALSO projected into typed columns
-- (profiles above, availability/constraints below). This table is the
-- record of what was asked and answered; those are the working copies.
-- -------------------------------------------------------------

CREATE TABLE onboarding_answers (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    question_key VARCHAR(20) NOT NULL,   -- '2.3', '6.7', matching the spec
    answer      JSON NOT NULL,           -- scalar, array, or object
    answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_onboarding_user_q (user_id, question_key),
    CONSTRAINT fk_onboarding_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Goals (SPEC-onboarding.md §2)
--
-- A table, not columns on profiles: goals change, and history is context
-- for later coaching. Goal-met / timeline-expiry handling is v2, but the
-- horizon is stored from the start so that work has something to read.
-- -------------------------------------------------------------

CREATE TABLE goals (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    primary_goal ENUM('lose_fat','build_muscle','recomp','improve_cardio',
                      'improve_strength','general_health') NOT NULL,
    secondary_goals JSON NULL,           -- array of the same vocabulary
    -- §2.3. The highest-signal answer in the quiz. Never parsed; always in
    -- the generation prompt verbatim.
    success_statement TEXT NULL,
    event_note      VARCHAR(500) NULL,
    event_date      DATE NULL,

    -- §2.5 / §3.6 — the user requests, Claude rules on feasibility.
    requested_timeline ENUM('8_weeks','12_weeks','16_weeks','6_months','1_year','none') NULL,
    claude_assessment  TEXT NULL,        -- why the request is or isn't realistic
    horizon_weeks      SMALLINT UNSIGNED NULL,  -- what the plan actually works to

    -- §2.6 — decides what the progress screen leads with.
    scale_vs_feel ENUM('scale','look_feel','both') NOT NULL DEFAULT 'both',

    status     ENUM('active','achieved','abandoned','superseded') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at   DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_goals_user (user_id, status),
    CONSTRAINT fk_goals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Weekly availability grid (SPEC-onboarding.md §7.1)
--
-- Seven rows per user, one per weekday. This is the fix for the first
-- draft's user-level gym_access enum: "full gym at work Mon-Fri, bodyweight
-- at home weekends" flattens to 'mixed' and destroys the fact generation
-- needs, then prescribes barbell work on a Saturday the user can't do.
--
-- Hardest structural constraint in plan generation — four days at 45 min is
-- a different program than six at 90, and intent doesn't override it.
-- -------------------------------------------------------------

CREATE TABLE availability (
    user_id     BIGINT UNSIGNED NOT NULL,
    weekday     TINYINT UNSIGNED NOT NULL,   -- 1=Mon .. 7=Sun (ISO-8601)
    can_train   ENUM('yes','no','sometimes') NOT NULL DEFAULT 'no',
    minutes     SMALLINT UNSIGNED NULL,
    access      ENUM('full_gym','home_gym','bodyweight','outdoors') NULL,
    equipment   JSON NULL,                   -- when access = home_gym
    is_chaotic  TINYINT(1) NOT NULL DEFAULT 0,
    preferred_time ENUM('early_morning','morning','midday','afternoon','evening','varies') NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, weekday),
    CONSTRAINT fk_avail_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_avail_weekday CHECK (weekday BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stated training capacity: the number of COMMITTED sessions per week.
-- Sessions beyond this are optional and never count against adherence
-- (SPEC-coaching.md §3.3a).
ALTER TABLE profiles
    ADD COLUMN committed_days_per_week TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER core_emphasis;

-- -------------------------------------------------------------
-- Food preferences & logistics (SPEC-onboarding.md §4, §5)
--
-- Preferences that generation reads every time. Food *exclusions* are not
-- here — they are constraints, in user_constraints below, because they need
-- tiers and reasons and are validated against.
-- -------------------------------------------------------------

CREATE TABLE food_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    cuisines        JSON NULL,
    dietary_pattern ENUM('none','vegetarian','vegan','pescatarian','keto','paleo',
                         'halal','kosher','other') NOT NULL DEFAULT 'none',
    meals_eaten     JSON NOT NULL,   -- ["breakfast","lunch","dinner","snacks"]
    -- §4.6 the freedom dial: how much of the menu is spelled out.
    structure       ENUM('spell_it_out','targets_and_options','mix')
                    NOT NULL DEFAULT 'mix',

    -- §5 logistics. Capacity, not preference — a hard limit on what can be
    -- prescribed. cooking_skill vs weekday_cook_minutes is the key tension:
    -- a good cook with 20 minutes needs efficient recipes, not simple ones.
    cooking_skill        ENUM('cant_cook','basic','competent','good','excellent') NULL,
    weekday_cook_minutes SMALLINT UNSIGNED NULL,
    weekend_cook_minutes SMALLINT UNSIGNED NULL,
    cooking_for          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    meal_preps           ENUM('eagerly','sometimes','no') NULL,
    budget_sensitivity   ENUM('tight','moderate','not_a_concern') NULL,
    kitchen_equipment    JSON NULL,

    -- §5.6 vs §5.7 — current habit vs planning request. Two different facts;
    -- the gap between them is itself a goal. eat_out_planned is a REQUEST:
    -- Claude negotiates it down if it would leave no plan at all.
    eat_out_current   TINYINT UNSIGNED NULL,
    eat_out_requested TINYINT UNSIGNED NULL,
    eat_out_agreed    TINYINT UNSIGNED NULL,

    caffeine_per_day  TINYINT UNSIGNED NULL,
    alcohol_per_week  TINYINT UNSIGNED NULL,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_foodpref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Training preferences (SPEC-onboarding.md §6)
-- -------------------------------------------------------------

CREATE TABLE training_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    experience   ENUM('never','beginner','intermediate','advanced','returning') NULL,
    currently_training ENUM('not_at_all','occasionally','1_2','3_4','5_plus') NULL,
    -- §6.3 — "my last serious gym grind worked amazingly well" means a
    -- proven approach exists and Claude should ask what it was.
    past_success TEXT NULL,
    self_strength ENUM('poor','below_average','average','good','strong') NULL,
    self_cardio   ENUM('poor','below_average','average','good','excellent') NULL,
    known_lifts   JSON NULL,
    cardio_willing JSON NULL,   -- §6.8
    -- §6.9 refused cardio also lands in user_constraints as soft: prescribing
    -- refused cardio is the fastest way to lose adherence.
    cardio_refused JSON NULL,
    preferred_split ENUM('full_body','upper_lower','ppl','no_preference')
                    NOT NULL DEFAULT 'no_preference',
    cardio_feeling  TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_trainpref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Safety constraints (SPEC-safety.md)
--
-- The core principle: constraints are DATA, not code. There is no hardcoded
-- rule anywhere saying "never prescribe X to a diabetic" — there is this
-- table, seeded from onboarding, editable by the user, consulted on every
-- generation.
--
-- hard: validated in code after generation. A violation is a bug, and the
--       plan is regenerated with the violation named (2 attempts, then fail
--       loud — never silently ship a violating plan).
-- soft: lives in the prompt. Claude may propose it WITH a reason; the user
--       accepts or vetoes.
--
-- Conditions are modifiers, not blocks (§3): T2 diabetes means carb timing
-- matters, not a carb ban. Cardiac history means a required warm-up and
-- gradual progression, not an intensity ceiling. Modelling conditions as
-- blocks produces a useless plan.
-- -------------------------------------------------------------

CREATE TABLE user_constraints (
    id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    kind    ENUM('food','movement','cardio','condition','equipment','target_floor') NOT NULL,
    tier    ENUM('hard','soft') NOT NULL,
    -- 'peanuts', 'back squat', 'type_2_diabetes', 'protein_g'
    subject VARCHAR(120) NOT NULL,
    -- Never a bare flag. The reason is what makes a constraint revisitable.
    reason  VARCHAR(500) NULL,
    -- Conditions only: the modifier text included in generation (§3).
    guidance TEXT NULL,
    -- target_floor only: the numeric floor Claude may not go below.
    -- Claude proposes, the user confirms; stored as user-owned thereafter.
    floor_value DECIMAL(8,2) NULL,
    -- §4 progression: {"target":"back squat","status":"working_toward"}
    -- The mechanism that stops month-three programming being identical to
    -- week one.
    progression JSON NULL,
    -- veto_promotion is the one automated write path, and it can only ever
    -- create SOFT constraints (SPEC-coaching.md §5.2).
    source  ENUM('onboarding','user_edit','veto_promotion','claude_proposed') NOT NULL,
    active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Generation reads active constraints by tier on every call.
    KEY idx_constraint_user (user_id, active, tier),
    KEY idx_constraint_kind (user_id, kind, active),
    CONSTRAINT fk_constraint_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every constraint change is audited (§6). Cheap, and it means any plan can
-- be explained after the fact.
CREATE TABLE user_constraint_audit (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    constraint_id BIGINT UNSIGNED NULL,   -- NULL once the constraint is deleted
    user_id       BIGINT UNSIGNED NOT NULL,
    action        ENUM('create','update','deactivate','reactivate','delete') NOT NULL,
    old_value     JSON NULL,
    new_value     JSON NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_caudit_user (user_id, created_at),
    CONSTRAINT fk_caudit_constraint FOREIGN KEY (constraint_id)
        REFERENCES user_constraints(id) ON DELETE SET NULL,
    CONSTRAINT fk_caudit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Self-reported physician clearance (§3.6). Recorded and flagged as
-- self-reported; context for Claude, and it gates nothing.
ALTER TABLE profiles
    ADD COLUMN physician_clearance ENUM('yes','no','not_asked') NULL AFTER birth_sex,
    ADD COLUMN medications TEXT NULL AFTER physician_clearance,
    ADD COLUMN trainer_notes TEXT NULL AFTER medications;  -- §3.7 / §10 free text
