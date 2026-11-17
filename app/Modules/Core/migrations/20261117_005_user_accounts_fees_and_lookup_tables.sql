-- ============================================================================
-- mojavez-v3 — Core module migration 005
-- User accounts (applicant realm) + notifications + fee tariff groups +
-- payment service ticks + settings + lookup tables new in v3.
-- ============================================================================

CREATE TABLE IF NOT EXISTS user_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NULL,
    username VARCHAR(50) NULL UNIQUE,
    mobile CHAR(11) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    avatar_document_id INT NULL,
    failed_login_count INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (avatar_document_id) REFERENCES documents(id) ON DELETE SET NULL,
    UNIQUE KEY uq_user_accounts_person (person_id),
    INDEX idx_user_accounts_mobile (mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_account_id INT NOT NULL,
    applicant_id INT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    INDEX idx_notif_user (user_account_id),
    INDEX idx_notif_unread (user_account_id, is_read),
    INDEX idx_notif_type (type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(8) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    decile_min TINYINT UNSIGNED NOT NULL,
    decile_max TINYINT UNSIGNED NOT NULL,
    total_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_group_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fee_group_id INT NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    item_label VARCHAR(150) NOT NULL,
    amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    FOREIGN KEY (fee_group_id) REFERENCES fee_groups(id) ON DELETE CASCADE,
    UNIQUE KEY uq_fee_group_item (fee_group_id, item_key),
    INDEX idx_fee_group_items_group (fee_group_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_service_ticks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    item_label VARCHAR(150) NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    done_reason VARCHAR(255) NULL,
    ticked_by INT NULL,
    ticked_at DATETIME NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    FOREIGN KEY (ticked_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_pay_tick (payment_id, item_key),
    INDEX idx_pay_ticks_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings: key/value, no AUTO_INCREMENT (key is the PK).
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NEW in v3 (not in v2): sessions table for persistent session storage
-- (alternative to PHP native file sessions, useful behind load balancers).
-- v2 used PHP native $_SESSION; v3 materialises a table per SCHEMA_OWNERSHIP.md.
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    payload TEXT NOT NULL,
    last_activity INT NOT NULL,
    user_id INT NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    INDEX idx_sessions_last_activity (last_activity),
    INDEX idx_sessions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NEW in v3: persian_date_cache (Persian date conversions cached).
-- v2 computed live; v3 caches to reduce computation overhead under load.
CREATE TABLE IF NOT EXISTS persian_date_cache (
    cache_key VARCHAR(64) PRIMARY KEY,
    gregorian_value DATE NOT NULL,
    persian_value VARCHAR(10) NOT NULL,
    cached_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_persian_date_gregorian (gregorian_value),
    INDEX idx_persian_date_persian (persian_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NEW in v3: email_queue (v2 had no email sending at all).
-- Phase 4 will wire Queue + Worker to drain this table.
CREATE TABLE IF NOT EXISTS email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    headers TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'queued',
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    error TEXT NULL,
    queued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    INDEX idx_email_queue_status (status, queued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NEW in v3: fee_tariffs (materialised table that v2 simulated via
-- fee_groups + fee_group_items + settings rows). v3 unifies them here.
CREATE TABLE IF NOT EXISTS fee_tariffs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_type_id INT NULL,
    decile_min TINYINT UNSIGNED NOT NULL,
    decile_max TINYINT UNSIGNED NOT NULL,
    base_amount BIGINT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    valid_from DATE NULL,
    valid_until DATE NULL,
    notes VARCHAR(500) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE CASCADE,
    INDEX idx_fee_tariffs_active (is_active, valid_until),
    INDEX idx_fee_tariffs_service (service_type_id, decile_min, decile_max)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
