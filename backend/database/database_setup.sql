-- ═══════════════════════════════════════════════════════════════════
--  PawDetect — Full Database Setup
--  Run this entire file in MySQL / phpMyAdmin (fresh install)
-- ═══════════════════════════════════════════════════════════════════

-- CREATE DATABASE IF NOT EXISTS pawdetect_db
--   CHARACTER SET utf8mb4
--   COLLATE utf8mb4_unicode_ci;
-- 
-- USE pawdetect_db;

-- ── Users ──────────────────────────────────────────────────────────
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS otp_verifications;
DROP TABLE IF EXISTS scan_history;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id            INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(150)  NOT NULL DEFAULT '',
    email         VARCHAR(255)  NOT NULL UNIQUE,
    password      VARCHAR(255)  NOT NULL,
    mobile        VARCHAR(20)   DEFAULT NULL,
    age           TINYINT UNSIGNED DEFAULT NULL,
    gender        ENUM('male','female','other','prefer_not_to_say') DEFAULT NULL,
    profile_pic   VARCHAR(500)  DEFAULT NULL,
    is_active     TINYINT(1)    DEFAULT 1,
    last_login    TIMESTAMP     NULL DEFAULT NULL,
    created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── OTP Verifications (registration + email-change + forgot-password) ──
CREATE TABLE otp_verifications (
    id               INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email            VARCHAR(255) NOT NULL,
    otp_hash         VARCHAR(255) NOT NULL,          -- bcrypt hash of OTP
    hashed_password  VARCHAR(255) DEFAULT NULL,      -- used during registration
    purpose          ENUM('register','forgot_password','change_email') DEFAULT 'register',
    new_email        VARCHAR(255) DEFAULT NULL,       -- used for change_email flow
    expires_at       TIMESTAMP NOT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Password Resets ────────────────────────────────────────────────
CREATE TABLE password_resets (
    id          INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT(11) UNSIGNED NOT NULL,
    reset_token VARCHAR(255) NOT NULL,
    expires_at  TIMESTAMP NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_reset_token (reset_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── User Sessions ──────────────────────────────────────────────────
CREATE TABLE user_sessions (
    id            INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT(11) UNSIGNED NOT NULL,
    session_token VARCHAR(255) NOT NULL,
    ip_address    VARCHAR(45),
    user_agent    TEXT,
    expires_at    TIMESTAMP NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_token (session_token),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Scan History (image uploads & camera captures) ─────────────────
CREATE TABLE scan_history (
    id           INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT(11) UNSIGNED NOT NULL,
    image_path   VARCHAR(500) NOT NULL,
    source       ENUM('upload','camera') DEFAULT 'upload',
    top_breed    VARCHAR(150) DEFAULT NULL,
    confidence   DECIMAL(5,2) DEFAULT NULL,
    predictions  TEXT DEFAULT NULL,              -- JSON string of top-3 predictions
    scanned_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_scanned_at (scanned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Dog Listings (History for adoption module) ────────────────────
CREATE TABLE dog_listings (
    id            INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT(11) UNSIGNED NOT NULL,
    name          VARCHAR(150)  NOT NULL,
    breed         VARCHAR(150)  NOT NULL,
    age_label     VARCHAR(100),
    gender        VARCHAR(20),
    size          VARCHAR(20),
    weight        VARCHAR(50),
    location      VARCHAR(255),
    description   TEXT,
    photo_path    VARCHAR(500),
    traits        TEXT,              -- JSON string of traits (vaccinated, neutered, etc.)
    special_needs TEXT,
    is_urgent     TINYINT(1) DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'PawDetect database updated with dog_listings!' AS Status;
DESCRIBE dog_listings;
