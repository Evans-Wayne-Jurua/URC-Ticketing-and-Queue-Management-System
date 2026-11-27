-- URC Ticketing and Queue Management System - MySQL schema
-- Mirrors the domain model currently in public/js/state/store.js so the
-- backend can become the real source of truth with no shape changes.

CREATE DATABASE IF NOT EXISTS urc_ticketing
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE urc_ticketing;

CREATE TABLE IF NOT EXISTS services (
    id       TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code     VARCHAR(20)  NOT NULL UNIQUE,
    prefix   CHAR(1)      NOT NULL,
    label_en VARCHAR(100) NOT NULL,
    label_lg VARCHAR(100) NOT NULL,
    -- next ticket number for this service, e.g. prefix=A, next_seq=8 -> "A-08".
    -- incremented under SELECT ... FOR UPDATE (see TokenRepository::takeToken)
    -- so concurrent kiosk taps can never get the same code.
    next_seq SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- "open" is an administrative decision a supervisor makes (see CLAUDE.md),
-- separate from a clerk actually logging in and serving from the counter.
CREATE TABLE IF NOT EXISTS counters (
    number  TINYINT UNSIGNED PRIMARY KEY,
    is_open BOOLEAN NOT NULL DEFAULT FALSE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('clerk', 'supervisor', 'admin') NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tokens (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code           VARCHAR(10) NOT NULL,               -- e.g. "A-07"
    service_id     TINYINT UNSIGNED NOT NULL,
    status         ENUM('waiting', 'serving', 'done', 'noshow') NOT NULL DEFAULT 'waiting',
    counter_number TINYINT UNSIGNED NULL,
    t1             DATETIME NOT NULL,                   -- arrival, stamped by the kiosk
    t2             DATETIME NULL,                        -- called, stamped by the clerk
    t3             DATETIME NULL,                        -- completed or no-show
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (service_id) REFERENCES services(id),
    FOREIGN KEY (counter_number) REFERENCES counters(number),
    INDEX idx_tokens_status (status),
    INDEX idx_tokens_t1 (t1)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_id           INT UNSIGNED NULL,
    trip               VARCHAR(100) NOT NULL,
    class_fee          INT UNSIGNED NOT NULL,
    qty                TINYINT UNSIGNED NOT NULL DEFAULT 1,
    payment_method     VARCHAR(30) NOT NULL,
    -- The payer's mobile money reference (MTN MoMo/Airtel Money only - this
    -- system never talks to those providers' APIs, see claudeSecure.md; it
    -- just records the claim and stops the SAME reference being reused
    -- across sales). NULL for Cash, where no reference exists.
    payment_reference  VARCHAR(50) NULL,
    total              INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (token_id) REFERENCES tokens(id),
    -- MySQL/MariaDB allow multiple NULLs through a UNIQUE index, so Cash
    -- sales (NULL reference) never collide with each other here.
    UNIQUE KEY uq_sales_payment_reference (payment_reference)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NULL,
    action         VARCHAR(50) NOT NULL,   -- e.g. 'call_next', 'complete_sale', 'open_counter'
    token_id       INT UNSIGNED NULL,
    counter_number TINYINT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (token_id) REFERENCES tokens(id)
) ENGINE=InnoDB;

-- Failed-login throttling (see src/Auth/RateLimiter.php). Keyed on the
-- submitted email rather than IP - simpler and still effective, and not
-- dependent on trusting a proxy header for the real client IP.
CREATE TABLE IF NOT EXISTS login_attempts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_identifier_time (identifier, created_at)
) ENGINE=InnoDB;

-- Reference data matching the frontend's current defaults (js/state/store.js)
INSERT IGNORE INTO services (code, prefix, label_en, label_lg) VALUES
    ('ticket',  'A', 'Buy a ticket', 'Gula tikiti'),
    ('collect', 'B', 'Collect pre-paid ticket', 'Ddira tikiti'),
    ('enquiry', 'C', 'Enquiry or refund', 'Ebibuuzo');

INSERT IGNORE INTO counters (number, is_open) VALUES
    (1, TRUE), (2, TRUE), (3, TRUE), (4, FALSE);
