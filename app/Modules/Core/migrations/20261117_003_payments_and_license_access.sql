-- ============================================================================
-- mojavez-v3 — Core module migration 003
-- Payments + license access + postal code changes + license files.
--
-- Ported from v2's schema.sql. NOTE the circular FK between
-- license_access_requests.payment_id → payments and
-- payments.linked_request_id → license_access_requests (both ON DELETE SET NULL).
-- license_access_requests is defined FIRST so the FK back from payments resolves.
-- ============================================================================

CREATE TABLE IF NOT EXISTS payment_channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    channel_type VARCHAR(50) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS postal_code_changes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    old_postal_code CHAR(10) NOT NULL,
    new_postal_code CHAR(10) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'در انتظار',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    reject_reason VARCHAR(255) NULL,
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_applicant_postal (applicant_id),
    INDEX idx_postal_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Defined BEFORE payments so the FK back from payments.linked_request_id resolves.
CREATE TABLE IF NOT EXISTS license_access_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    is_first_access TINYINT(1) NOT NULL DEFAULT 0,
    access_token CHAR(64) NULL UNIQUE,
    requires_payment TINYINT(1) NOT NULL DEFAULT 0,
    amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    payment_id INT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'در انتظار',
    expires_at DATETIME NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    reject_reason VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_lar_status (status),
    INDEX idx_lar_applicant (applicant_id),
    INDEX idx_lar_token (access_token),
    INDEX idx_lar_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    user_account_id INT NULL,
    payment_channel_id INT NULL,
    purpose VARCHAR(64) NOT NULL DEFAULT 'هزینهٔ ثبت‌نام',
    amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    original_amount BIGINT UNSIGNED NULL,
    final_amount BIGINT UNSIGNED NULL,
    discount_reason VARCHAR(500) NULL,
    fee_group_id INT NULL,
    is_rural_exempt TINYINT(1) NOT NULL DEFAULT 0,
    fallback_used TINYINT(1) NOT NULL DEFAULT 0,
    suspicion_flags VARCHAR(255) NULL,
    reference_code VARCHAR(50) NULL,
    paid_at DATE NULL,
    receipt_document_id INT NULL,
    linked_request_id INT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'در انتظار رسید',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    reject_reason VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (payment_channel_id) REFERENCES payment_channels(id) ON DELETE SET NULL,
    FOREIGN KEY (receipt_document_id) REFERENCES documents(id) ON DELETE SET NULL,
    FOREIGN KEY (linked_request_id) REFERENCES license_access_requests(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_pay_status (status),
    INDEX idx_pay_applicant (applicant_id),
    INDEX idx_pay_purpose (purpose),
    INDEX idx_pay_user (user_account_id),
    INDEX idx_pay_group (fee_group_id),
    INDEX idx_pay_suspicion (suspicion_flags(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS license_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL UNIQUE,
    document_id INT NOT NULL,
    download_count INT NOT NULL DEFAULT 0,
    first_accessed_at DATETIME NULL,
    issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    user_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    field_changed VARCHAR(50) NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    note VARCHAR(500) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_audit_payment (payment_id),
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
