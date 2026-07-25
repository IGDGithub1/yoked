-- =============================================================
-- Migration 004 — actuals: training logs, food logs, check-ins,
--                 vetoes, chat, adherence
--
-- Implements SPEC-coaching.md §4, §5, §6, §7.
--
-- Everything here records what ACTUALLY happened, against the plan version
-- that was live at log time. The prescribed/actual pair is the whole signal
-- the coaching engine reads.
--
-- Nutrition intake carries over from Keto Tracker (specs/SPEC-nutrition.md):
-- net carbs computed at intake, the additive manualDelta correction, AI food
-- search, barcode lookups, favorites. The JSONB week blob is replaced by
-- real rows, which is the main practical win — a keystroke writes one row
-- instead of re-PUTting the entire week.
-- =============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -------------------------------------------------------------
-- Logged days — one row per user per calendar date
--
-- Carries the daily check-in (§4.1) and the cached goal verdict. The
-- plan_version_id records which version this day was measured against, so
-- adherence stays meaningful after the plan changes mid-week (§2).
-- -------------------------------------------------------------

CREATE TABLE logged_days (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id  BIGINT UNSIGNED NOT NULL,
    log_date DATE NOT NULL,
    -- Which plan version this day is judged against. NULL during baseline
    -- week 1, when there is deliberately no prescription.
    plan_version_id BIGINT UNSIGNED NULL,

    -- Daily check-in (§4.1). Read as a delta against the profile baselines.
    energy    TINYINT UNSIGNED NULL,   -- 1..5
    sleep_hours DECIMAL(3,1) NULL,
    sleep_quality TINYINT UNSIGNED NULL,   -- 1..5
    soreness  TINYINT UNSIGNED NULL,   -- 1..5
    mood      TINYINT UNSIGNED NULL,   -- 1..5
    checked_in_at DATETIME NULL,
    notes     TEXT NULL,

    -- Cached verdicts from the goal evaluator. Recomputed whenever entries,
    -- targets, or the plan change. Server-side only — the client displays the
    -- verdict and never computes it.
    macro_on_target TINYINT(1) NULL,
    -- Set when calories fell short but the macros landed: adherent WITH A
    -- NOTE, not a failure (SPEC-safety.md §8).
    macro_short_but_ok TINYINT(1) NOT NULL DEFAULT 0,
    failure_count   TINYINT UNSIGNED NULL,
    proximity       DECIMAL(6,4) NULL,   -- lower is better, 0 = perfect
    -- Committed sessions only (§3.3a) — optional sessions never count against.
    sessions_prescribed TINYINT UNSIGNED NULL,
    sessions_completed  TINYINT UNSIGNED NULL,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_lday_user_date (user_id, log_date),
    -- Streak and adherence scans walk this backwards from yesterday.
    KEY idx_lday_user_target (user_id, macro_on_target, log_date),
    KEY idx_lday_plan (plan_version_id),
    CONSTRAINT fk_lday_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_lday_plan FOREIGN KEY (plan_version_id)
        REFERENCES plan_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Training logs (§4.4)
--
-- Per-EXERCISE, not per-set. Actual weight, actual reps, one RPE: about
-- three taps. Per-set logging is more accurate and gets abandoned by week
-- three, and abandoned logging ends the app.
-- -------------------------------------------------------------

CREATE TABLE logged_sessions (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id  BIGINT UNSIGNED NOT NULL,
    logged_day_id BIGINT UNSIGNED NOT NULL,
    -- NULL = unprescribed session (baseline week 1, or the user just trained).
    prescribed_session_id BIGINT UNSIGNED NULL,
    status   ENUM('completed','partial','skipped','substituted') NOT NULL,
    actual_minutes SMALLINT UNSIGNED NULL,
    -- Overall session RPE, separate from per-exercise.
    session_rpe TINYINT UNSIGNED NULL,
    notes    TEXT NULL,
    -- Set when trained alongside a buddy: powers the shared adherence signal
    -- (§10.4) and tells Claude the pairing is actually working.
    trained_with_buddy TINYINT(1) NOT NULL DEFAULT 0,
    logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lsession_day (logged_day_id),
    KEY idx_lsession_user (user_id, logged_at),
    KEY idx_lsession_prescribed (prescribed_session_id),
    CONSTRAINT fk_lsession_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_lsession_day  FOREIGN KEY (logged_day_id)
        REFERENCES logged_days(id) ON DELETE CASCADE,
    CONSTRAINT fk_lsession_prescribed FOREIGN KEY (prescribed_session_id)
        REFERENCES prescribed_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE logged_exercises (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    logged_session_id BIGINT UNSIGNED NOT NULL,
    exercise_id BIGINT UNSIGNED NOT NULL,
    -- NULL when the user did something not prescribed.
    prescribed_exercise_id BIGINT UNSIGNED NULL,
    sets_completed TINYINT UNSIGNED NULL,
    -- Actual reps achieved. Text for the same reason as the prescription.
    actual_reps VARCHAR(20) NULL,
    actual_weight_kg DECIMAL(6,2) NULL,
    actual_seconds SMALLINT UNSIGNED NULL,
    actual_distance_m SMALLINT UNSIGNED NULL,
    -- The single most valuable field for progression. Drives next week's
    -- loads: "RPE 7 at 85 says you had it."
    rpe         TINYINT UNSIGNED NULL,
    skipped     TINYINT(1) NOT NULL DEFAULT 0,
    notes       VARCHAR(300) NULL,
    logged_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lexercise_session (logged_session_id),
    -- Load history / PR lookups per user per exercise.
    KEY idx_lexercise_exercise (exercise_id, logged_at),
    CONSTRAINT fk_lexercise_session FOREIGN KEY (logged_session_id)
        REFERENCES logged_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_lexercise_exercise FOREIGN KEY (exercise_id)
        REFERENCES exercises(id) ON DELETE RESTRICT,
    CONSTRAINT fk_lexercise_prescribed FOREIGN KEY (prescribed_exercise_id)
        REFERENCES prescribed_exercises(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Food logs (carried over from Keto Tracker, SPEC-nutrition.md)
--
-- logged_meals holds the additive manual delta; logged_entries holds items.
-- Meal total = delta + SUM(items). The delta is ADDITIVE, not an override:
-- log "chicken + broccoli" from search, then nudge +50 cal for the cooking
-- oil, and the nudge survives adding or removing items. Most-used correction
-- path in the original app — preserved deliberately.
-- -------------------------------------------------------------

CREATE TABLE logged_meals (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    logged_day_id BIGINT UNSIGNED NOT NULL,
    slot     ENUM('breakfast','lunch','dinner','snack_am','snack_pm','snack_eve') NOT NULL,
    -- How this meal related to what was prescribed. 'as_planned' is the
    -- one-tap path. Persistent 'substituted' means the MENU is wrong, not
    -- the user (§4.3).
    adherence ENUM('as_planned','substituted','skipped','unplanned')
              NOT NULL DEFAULT 'unplanned',
    prescribed_meal_id BIGINT UNSIGNED NULL,
    -- SIGNED: adjustments go both ways.
    delta_calories  SMALLINT NOT NULL DEFAULT 0,
    delta_protein_g DECIMAL(6,1) NOT NULL DEFAULT 0,
    delta_fat_g     DECIMAL(6,1) NOT NULL DEFAULT 0,
    delta_carbs_g   DECIMAL(6,1) NOT NULL DEFAULT 0,
    notes    VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_lmeal_day_slot (logged_day_id, slot),
    KEY idx_lmeal_prescribed (prescribed_meal_id),
    CONSTRAINT fk_lmeal_day FOREIGN KEY (logged_day_id)
        REFERENCES logged_days(id) ON DELETE CASCADE,
    CONSTRAINT fk_lmeal_prescribed FOREIGN KEY (prescribed_meal_id)
        REFERENCES prescribed_meals(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE logged_entries (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    logged_meal_id BIGINT UNSIGNED NOT NULL,
    name     VARCHAR(200) NOT NULL,
    serving_g SMALLINT UNSIGNED NULL,
    calories  DECIMAL(7,1) NOT NULL DEFAULT 0,
    protein_g DECIMAL(6,1) NOT NULL DEFAULT 0,
    fat_g     DECIMAL(6,1) NOT NULL DEFAULT 0,
    -- NET carbs (total - fiber), computed at intake. Everything downstream
    -- uses net and calls it carbs.
    carbs_g   DECIMAL(6,1) NOT NULL DEFAULT 0,
    fiber_g   DECIMAL(6,1) NULL,
    total_carbs_g DECIMAL(6,1) NULL,
    source    ENUM('manual','ai','barcode','favorite','as_planned') NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(80) NULL,          -- UPC, etc.
    favorite_id BIGINT UNSIGNED NULL,     -- drives the star marker in the UI
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lentry_meal (logged_meal_id),
    CONSTRAINT fk_lentry_meal FOREIGN KEY (logged_meal_id)
        REFERENCES logged_meals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE favorite_foods (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id  BIGINT UNSIGNED NOT NULL,
    name     VARCHAR(200) NOT NULL,
    serving_g SMALLINT UNSIGNED NULL,
    calories  DECIMAL(7,1) NOT NULL DEFAULT 0,
    protein_g DECIMAL(6,1) NOT NULL DEFAULT 0,
    fat_g     DECIMAL(6,1) NOT NULL DEFAULT 0,
    carbs_g   DECIMAL(6,1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- utf8mb4_unicode_ci is case-insensitive, so this enforces the
    -- case-insensitive dedupe that isFavorited() did in JS.
    UNIQUE KEY uk_fav_user_name (user_id, name),
    CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE logged_entries
    ADD CONSTRAINT fk_lentry_favorite FOREIGN KEY (favorite_id)
        REFERENCES favorite_foods(id) ON DELETE SET NULL;

-- Barcode cache. The same packaged foods get scanned repeatedly, so proxying
-- Open Food Facts server-side and caching here avoids the network entirely
-- on a repeat scan.
CREATE TABLE food_barcodes (
    upc          VARCHAR(32) NOT NULL,
    name         VARCHAR(200) NOT NULL,
    serving_g    SMALLINT UNSIGNED NULL,
    cal_100g     DECIMAL(7,1) NULL,
    protein_100g DECIMAL(6,1) NULL,
    fat_100g     DECIMAL(6,1) NULL,
    carbs_100g   DECIMAL(6,1) NULL,
    fiber_100g   DECIMAL(6,1) NULL,
    source       ENUM('openfoodfacts','ai') NOT NULL DEFAULT 'openfoodfacts',
    fetched_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (upc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Weekly check-ins (§7.2)
--
-- Weight and measurements weekly; photos every two weeks (weekly shows too
-- little change to motivate). Trend over points: weight moves for a dozen
-- reasons daily, so evaluation reads direction over time, never a single
-- reading.
-- -------------------------------------------------------------

CREATE TABLE weekly_checkins (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    week_start DATE NOT NULL,
    weight_kg  DECIMAL(5,2) NULL,
    waist_cm   DECIMAL(5,1) NULL,   -- the one that matters most for visceral fat
    hips_cm    DECIMAL(5,1) NULL,
    chest_cm   DECIMAL(5,1) NULL,
    arm_cm     DECIMAL(5,1) NULL,
    thigh_cm   DECIMAL(5,1) NULL,
    neck_cm    DECIMAL(5,1) NULL,
    -- User's own read on the week.
    self_report TEXT NULL,
    -- §7.2a — the adherence dividend. A user who follows the plan earns input
    -- into it: "glutes aren't developing as fast as I expected."
    emphasis_request TEXT NULL,
    -- Claude's response to the week + the request.
    claude_review TEXT NULL,
    -- Created by cron (§ resolved item 2); nudged if unanswered, because
    -- otherwise a quiet user never gets re-planned.
    status     ENUM('pending','completed','skipped') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_checkin_user_week (user_id, week_start),
    KEY idx_checkin_status (status, week_start),
    CONSTRAINT fk_checkin_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE checkin_photos (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    checkin_id BIGINT UNSIGNED NOT NULL,
    media_id   BIGINT UNSIGNED NOT NULL,
    angle      ENUM('front','side','back') NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cphoto_checkin_angle (checkin_id, angle),
    CONSTRAINT fk_cphoto_checkin FOREIGN KEY (checkin_id)
        REFERENCES weekly_checkins(id) ON DELETE CASCADE,
    CONSTRAINT fk_cphoto_media FOREIGN KEY (media_id)
        REFERENCES media(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standing emphasis granted at a check-in (§7.2a). Stored with the adherence
-- context that justified it, so later weeks keep honouring it without the
-- user re-asking.
CREATE TABLE emphasis_grants (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    checkin_id BIGINT UNSIGNED NULL,
    request    TEXT NOT NULL,
    -- Declined requests are kept: a user with poor adherence asking to drop
    -- the parts they've been skipping is a conversation, not a setting.
    decision   ENUM('granted','declined','partial') NOT NULL,
    reasoning  TEXT NULL,
    adherence_context JSON NULL,   -- the numbers that justified the decision
    active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_emphasis_user (user_id, active),
    CONSTRAINT fk_emphasis_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_emphasis_checkin FOREIGN KEY (checkin_id)
        REFERENCES weekly_checkins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Vetoes (§5)
--
-- A reason is required — the reason is the whole value. Scope distinguishes
-- "thunderstorms today" from "I hate salmon, never again"; the latter
-- promotes to a soft constraint, which is the one automated
-- constraint-write path in the app.
-- -------------------------------------------------------------

CREATE TABLE vetoes (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id  BIGINT UNSIGNED NOT NULL,
    subject_type ENUM('session','exercise','meal') NOT NULL,
    subject_id   BIGINT UNSIGNED NOT NULL,   -- prescribed_* id
    reason_code  ENUM('no_time','dont_like','cant_do','unwell','weather',
                      'travel','equipment','other') NOT NULL,
    reason_text  VARCHAR(500) NULL,
    scope    ENUM('today','standing') NOT NULL DEFAULT 'today',
    -- Claude may DECLINE a veto and hold the line: no "nah, don't want to"
    -- without a very good excuse. A declined veto is still logged, because
    -- the pattern is signal — someone vetoing legs every Thursday for four
    -- weeks needs a conversation, not silent accommodation.
    outcome  ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
    claude_response TEXT NULL,
    -- Set when a standing veto became a soft constraint.
    promoted_constraint_id BIGINT UNSIGNED NULL,
    -- The plan version generated in response.
    resulting_plan_version_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Rate-based pattern detection (§ resolved item 4): four vetoes in a year
    -- says nothing, four in four weeks is a pattern.
    KEY idx_veto_user_time (user_id, created_at),
    KEY idx_veto_subject (subject_type, subject_id),
    CONSTRAINT fk_veto_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_veto_constraint FOREIGN KEY (promoted_constraint_id)
        REFERENCES user_constraints(id) ON DELETE SET NULL,
    CONSTRAINT fk_veto_plan FOREIGN KEY (resulting_plan_version_id)
        REFERENCES plan_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Chat / interjection (§6)
--
-- The user supplies FACTS; Claude decides what to do about them. A user
-- message never edits the plan — it is recorded as a stated circumstance,
-- Claude evaluates it, and only Claude's decision produces a plan version.
-- This is structural: there is no code path from user text to plan mutation,
-- so "chat that can be talked into anything" is not a failure mode that
-- exists here.
-- -------------------------------------------------------------

CREATE TABLE chat_turns (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id  BIGINT UNSIGNED NOT NULL,
    role     ENUM('user','assistant') NOT NULL,
    body     TEXT NOT NULL,
    -- Set on assistant turns that changed the plan.
    resulting_plan_version_id BIGINT UNSIGNED NULL,
    -- Assistant's classification of the preceding user turn.
    outcome  ENUM('acknowledged','question','plan_changed','declined') NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_chat_user (user_id, id),
    CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_plan FOREIGN KEY (resulting_plan_version_id)
        REFERENCES plan_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A fact about reality extracted from chat, with a scope. "Travelling this
-- week" expires; "I hate salmon" is permanent and gets promoted. Without the
-- expiry the app reshuffles forever around a trip that ended weeks ago.
CREATE TABLE circumstances (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id  BIGINT UNSIGNED NOT NULL,
    chat_turn_id BIGINT UNSIGNED NULL,
    kind     ENUM('travel','illness','injury','schedule','equipment','other') NOT NULL,
    detail   VARCHAR(500) NOT NULL,
    starts_on DATE NULL,
    ends_on   DATE NULL,      -- NULL = open-ended, until the user says otherwise
    active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_circ_user (user_id, active, ends_on),
    CONSTRAINT fk_circ_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_circ_turn FOREIGN KEY (chat_turn_id)
        REFERENCES chat_turns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Claude call log
--
-- Every API call, for cost visibility and debugging. Also the record of
-- constraint-violation retries (SPEC-safety.md §5) — a plan that needed two
-- attempts is worth being able to find.
-- -------------------------------------------------------------

CREATE TABLE ai_calls (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id  BIGINT UNSIGNED NULL,
    purpose  ENUM('plan_generation','provisional_plan','baseline_analysis',
                  'drift_eval','veto_replacement','interjection',
                  'weekly_review','food_search','other') NOT NULL,
    model    VARCHAR(60) NOT NULL,
    input_tokens  INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,
    cached_tokens INT UNSIGNED NULL,
    retry_count   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- Which hard constraints tripped, when a retry was needed.
    violations    JSON NULL,
    ok       TINYINT(1) NOT NULL DEFAULT 1,
    error    VARCHAR(500) NULL,
    duration_ms INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_aicall_user (user_id, created_at),
    KEY idx_aicall_purpose (purpose, created_at),
    CONSTRAINT fk_aicall_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
