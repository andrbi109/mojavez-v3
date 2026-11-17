-- ============================================================================
-- mojavez-v3 — Core module migration 004
-- Wizard + form fields + license titles + applicant field values +
-- applicant revisions + sms templates + user sms entries + fee groups.
-- ============================================================================

CREATE TABLE IF NOT EXISTS wizard_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    view_key VARCHAR(64) NOT NULL UNIQUE,
    title VARCHAR(150) NOT NULL,
    service_type_id INT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    icon VARCHAR(50) NULL,
    FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wizard_step_id INT NULL,
    service_type_id INT NULL,
    field_key VARCHAR(64) NOT NULL UNIQUE,
    field_label VARCHAR(150) NOT NULL,
    field_type VARCHAR(32) NOT NULL DEFAULT 'text',
    field_options TEXT NULL,
    validation_rules TEXT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (wizard_step_id) REFERENCES wizard_steps(id) ON DELETE SET NULL,
    FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE CASCADE,
    INDEX idx_form_fields_step (wizard_step_id),
    INDEX idx_form_fields_service (service_type_id),
    INDEX idx_form_fields_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS applicant_field_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    field_id INT NOT NULL,
    field_value TEXT NULL,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    FOREIGN KEY (field_id) REFERENCES form_fields(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_applicant_field (applicant_id, field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS license_titles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_type_id INT NULL,
    category VARCHAR(100) NULL,
    title VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE CASCADE,
    UNIQUE KEY uq_service_title (service_type_id, title),
    INDEX idx_license_titles_service (service_type_id),
    INDEX idx_license_titles_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS applicant_revisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    payload JSON NOT NULL,
    note VARCHAR(500) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'در انتظار',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    reject_reason VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_rev_status (status),
    INDEX idx_rev_applicant (applicant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    sample_text TEXT NULL,
    target_status VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    -- NEW in v3: template for golden-link auto-confirm notifications.
    -- Seeded by Phase 4 when NotificationService is built.
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sms_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    sms_template_id INT NOT NULL,
    sms_code VARCHAR(50) NULL,
    sms_text TEXT NULL,
    received_at DATE NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'در انتظار',
    applied_status TINYINT(1) NOT NULL DEFAULT 0,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    reject_reason VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    FOREIGN KEY (sms_template_id) REFERENCES sms_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sms_status (status),
    INDEX idx_sms_applicant (applicant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS identity_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    national_code CHAR(10) NOT NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identity_checks_national (national_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
