-- =============================================================
-- Migration 001 — foundation: identity, auth, media, notifications
--
-- Derived from the Friendspace schema (same hand-rolled PHP 8 / MySQL
-- patterns, same shared hosting), trimmed to what Yoked needs. Yoked is
-- its own project, not an extension of Friendspace — so this is 001, not 013.
--
-- Conventions, matching the Friendspace house style:
--   * BIGINT UNSIGNED ids throughout. The keto-extract proposal declared
--     INT UNSIGNED for user FKs against a BIGINT users.id, which is why it
--     could never have run — MySQL rejects mismatched FK types.
--   * DATETIME, not TIMESTAMP. All UTC; the client converts.
--   * uk_ unique, idx_ index, fk_ foreign key.
--
-- MySQL 8.x / MariaDB 10.6+, InnoDB, utf8mb4.
-- =============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -------------------------------------------------------------
-- Migration tracker
--
-- Friendspace's migrations were not idempotent and had no applied-log,
-- which is how migration 010 got skipped in a deploy. bin/migrate.php
-- records every file it runs here and refuses to re-run one.
-- -------------------------------------------------------------

CREATE TABLE schema_migrations (
    filename   VARCHAR(255) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Identity
-- -------------------------------------------------------------

CREATE TABLE users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(30)  NOT NULL,
    display_name  VARCHAR(60)  NOT NULL,
    email         VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    avatar_media_id BIGINT UNSIGNED NULL,   -- FK added after media exists
    role          ENUM('member','admin')     NOT NULL DEFAULT 'member',
    status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
    -- Onboarding is a gate: no plan can be generated until it completes.
    -- See SPEC-onboarding.md tiering (§1-§3 blocking, §4-§9 resumable).
    onboarding_state ENUM('pending','in_progress','baseline','active')
                     NOT NULL DEFAULT 'pending',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_username (username),
    UNIQUE KEY uk_users_email (email),
    KEY idx_users_onboarding (onboarding_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invites (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code       CHAR(20) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    used_by    BIGINT UNSIGNED NULL,
    used_at    DATETIME NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_invites_code (code),
    KEY idx_invites_created_by (created_by),
    CONSTRAINT fk_invites_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_invites_user    FOREIGN KEY (used_by)    REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persistent auto-login (selector : validator, 60-day sliding expiry).
CREATE TABLE auth_tokens (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    selector     CHAR(32) NOT NULL,
    token_hash   CHAR(64) NOT NULL,
    expires_at   DATETIME NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_authtok_selector (selector),
    KEY idx_authtok_user (user_id),
    CONSTRAINT fk_authtok_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    bucket       VARCHAR(120) NOT NULL,
    window_start DATETIME NOT NULL,
    hits         INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Social graph
--
-- Normalized ordered pair so a relationship can never exist twice.
-- Buddy pairing (SPEC-coaching.md §10) requires an accepted friendship.
-- -------------------------------------------------------------

CREATE TABLE friendships (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_lo      BIGINT UNSIGNED NOT NULL,
    user_hi      BIGINT UNSIGNED NOT NULL,
    requester_id BIGINT UNSIGNED NOT NULL,
    status       ENUM('pending','accepted','blocked') NOT NULL DEFAULT 'pending',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_friend_pair (user_lo, user_hi),
    KEY idx_friend_hi (user_hi, status),
    KEY idx_friend_lo (user_lo, status),
    CONSTRAINT fk_friend_lo FOREIGN KEY (user_lo) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_friend_hi FOREIGN KEY (user_hi) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_friend_order CHECK (user_lo < user_hi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Media
--
-- Progress photos are the sensitive case: stored outside the web root and
-- served through a gateway that checks ownership. Default-private, per
-- SPEC-onboarding.md 9.5.
-- -------------------------------------------------------------

CREATE TABLE media (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id   BIGINT UNSIGNED NOT NULL,
    kind       ENUM('image','progress_photo') NOT NULL,
    path       VARCHAR(500) NOT NULL,
    mime       VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    width      INT UNSIGNED NULL,
    height     INT UNSIGNED NULL,
    variants   JSON NULL,   -- {"thumb": "...", "medium": "..."}
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_media_owner (owner_id, kind),
    CONSTRAINT fk_media_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD CONSTRAINT fk_users_avatar FOREIGN KEY (avatar_media_id)
        REFERENCES media(id) ON DELETE SET NULL;

-- -------------------------------------------------------------
-- Notifications
--
-- In-app only. Nudges (SPEC-coaching.md §9) are notifications with a
-- nudge type; buddy nudges are gentle regardless of the user's tone.
-- -------------------------------------------------------------

CREATE TABLE notifications (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    actor_id     BIGINT UNSIGNED NULL,      -- NULL = the app itself (nudges, plans)
    type         VARCHAR(40) NOT NULL,
    subject_type VARCHAR(40) NULL,
    subject_id   BIGINT UNSIGNED NULL,
    body         VARCHAR(500) NULL,         -- generated copy, in the user's tone
    read_at      DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_user (user_id, read_at, id),
    CONSTRAINT fk_notif_user  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
