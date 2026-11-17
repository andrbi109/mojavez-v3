-- ============================================================================
-- mojavez-v3 — Core module migration 002
-- Core identity + applicant tables (users, persons, service_types, applicants,
-- job_details, property_details, documents, document_types, status_log).
--
-- Ported from Mojavez-v2's schema.sql, with v3 additions:
--   - applicants: + national_license_request_number (UNIQUE, nullable, golden-link anchor)
--                 + matched_at, match_confidence
--                 + version (optimistic concurrency, default 1)
--                 + deleted_at (soft-delete)
--                 + consent_external_share (default 0)
--   - persons:    + father_name, birth_date (for golden-link pattern matching)
--   - status_log: + triggered_by ('admin'|'user'|'system'|'auto_sync')
--   - document_types: NEW table (v2 had it as a PHP const — materialised
--                     in v3 per SCHEMA_OWNERSHIP.md matrix).
--
-- All VARCHAR(32) for status fields (NOT MySQL ENUM — CODING_CONVENTIONS §5.2).
-- InnoDB engine, utf8mb4_unicode_ci collation, +00:00 session timezone.
-- All FKs explicit with ON DELETE CASCADE for child tables / SET NULL for refs.
-- ============================================================================

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'admin',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS persons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    national_code CHAR(10) NOT NULL UNIQUE,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    father_name VARCHAR(50) NULL,
    birth_date DATE NULL,
    mobile CHAR(11) NOT NULL,
    mobile_2 CHAR(11) NULL,
    mobile_3 CHAR(11) NULL,
    postal_code CHAR(10) NULL,
    person_type ENUM('حقیقی','حقوقی') NOT NULL DEFAULT 'حقیقی',
    company_name VARCHAR(150) NULL,
    company_national_id CHAR(11) NULL,
    reg_number VARCHAR(30) NULL,
    reg_date DATE NULL,
    company_type VARCHAR(60) NULL,
    economic_code VARCHAR(20) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_person_mobile (mobile),
    INDEX idx_person_mobile_2 (mobile_2),
    INDEX idx_person_mobile_3 (mobile_3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL UNIQUE,
    authority VARCHAR(150) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS applicants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NULL,
    parent_application_id INT NULL,
    service_type_id INT NULL,
    user_account_id INT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    national_code CHAR(10) NOT NULL,
    postal_code CHAR(10) NOT NULL,
    mobile CHAR(11) NOT NULL,
    tracking_code VARCHAR(20) NOT NULL UNIQUE,
    birth_date DATE NULL,
    father_name VARCHAR(50) NULL,
    current_status VARCHAR(32) NOT NULL DEFAULT 'پیش‌نویس',
    applicant_type ENUM('حقیقی','حقوقی') NOT NULL DEFAULT 'حقیقی',
    marital_status ENUM('مجرد','متأهل') DEFAULT 'مجرد',
    children_count TINYINT UNSIGNED DEFAULT 0,
    physical_condition ENUM('سالم','معلولیت') DEFAULT 'سالم',
    disability_details VARCHAR(255) NULL,
    income_decile TINYINT UNSIGNED NULL,
    has_court_case ENUM('بله','خیر') DEFAULT 'خیر',
    has_active_loan ENUM('بله','خیر') DEFAULT 'خیر',
    has_overdue_installments ENUM('بله','خیر') DEFAULT 'خیر',
    priority TINYINT(1) DEFAULT 0,
    payment_required TINYINT(1) NOT NULL DEFAULT 0,
    payment_requested_at DATETIME NULL,
    license_state VARCHAR(32) NOT NULL DEFAULT 'جاری',
    license_valid_until DATE NULL,
    -- NEW in v3: golden-link anchor + optimistic concurrency + soft-delete + consent
    national_license_request_number VARCHAR(32) NULL,
    matched_at DATETIME NULL,
    match_confidence INT NULL,
    version INT NOT NULL DEFAULT 1,
    deleted_at DATETIME NULL,
    consent_external_share TINYINT(1) NOT NULL DEFAULT 0,
    registration_started_at DATETIME NULL,
    registration_completed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_applicants_national_license (national_license_request_number),
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_application_id) REFERENCES applicants(id) ON DELETE SET NULL,
    FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE SET NULL,
    INDEX idx_tracking_code (tracking_code),
    INDEX idx_national_code (national_code),
    INDEX idx_status (current_status),
    INDEX idx_priority (priority),
    INDEX idx_mobile (mobile),
    INDEX idx_completed_priority (registration_completed_at, priority),
    INDEX idx_person (person_id),
    INDEX idx_parent_app (parent_application_id),
    INDEX idx_license_state (license_state),
    INDEX idx_applicant_type (applicant_type),
    INDEX idx_applicant_user (user_account_id),
    INDEX idx_applicants_deleted (deleted_at),
    INDEX idx_applicants_national_license (national_license_request_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    license_title VARCHAR(100) NOT NULL,
    experience_months INT NOT NULL,
    training_method ENUM('در خانه', 'استاد شاگردی', 'کارگاهی') NOT NULL,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_applicant_job (applicant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    ownership_status ENUM('شخص', 'مستأجر', 'سرپرست') NOT NULL,
    province VARCHAR(50) NOT NULL,
    county VARCHAR(50) NOT NULL,
    city VARCHAR(50) NOT NULL,
    district VARCHAR(50) NULL,
    rural_district VARCHAR(50) NULL,
    village VARCHAR(50) NULL,
    electricity_bill_id VARCHAR(20) NOT NULL,
    address_street VARCHAR(100) NULL,
    address_alley VARCHAR(100) NULL,
    address_plaque VARCHAR(20) NULL,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_applicant_property (applicant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    doc_type VARCHAR(50) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    INDEX idx_applicant_docs (applicant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NEW in v3: document_types table (was a PHP const in v2; materialised here
-- per docs/SCHEMA_OWNERSHIP.md matrix).
CREATE TABLE IF NOT EXISTS document_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_type_key VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    category ENUM('user_upload', 'admin_add') NOT NULL DEFAULT 'user_upload',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    status_name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    -- NEW in v3: triggered_by ('admin'|'user'|'system'|'auto_sync')
    triggered_by VARCHAR(32) NOT NULL DEFAULT 'admin',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    INDEX idx_applicant_log (applicant_id),
    INDEX idx_status_log_created (created_at),
    INDEX idx_status_log_triggered (triggered_by, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
