-- ============================================================================
-- mojavez-v3 — NationalGateway module migration 001
-- Tables for caching upstream national-gateway data + golden-link audit.
--
-- Per docs/SCHEMA_OWNERSHIP.md §2 — NationalGateway owns:
--   national_licenses, national_lookups, sync_audit_log, national_filters,
--   national_batches.
--
-- InnoDB, utf8mb4_unicode_ci, all FKs explicit.
-- ============================================================================

CREATE TABLE IF NOT EXISTS national_licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(64) NOT NULL UNIQUE,
    applicant_name VARCHAR(255) NOT NULL DEFAULT '',
    user_image VARCHAR(500) NULL,
    license_title VARCHAR(255) NOT NULL DEFAULT '',
    organization_title VARCHAR(255) NOT NULL DEFAULT '',
    province_title VARCHAR(100) NOT NULL DEFAULT '',
    township_title VARCHAR(100) NOT NULL DEFAULT '',
    responded_at DATETIME NULL,
    status_slug VARCHAR(32) NOT NULL DEFAULT 'unknown',
    status_id VARCHAR(32) NULL,
    status_title VARCHAR(100) NULL,
    -- NEW in v3: golden-link anchor — the masked national code visible on the
    -- upstream track page (e.g. "073****269"). PatternMatcher compares this
    -- against applicants.national_code via Normalizer::nationalCodeMask().
    masked_national_code VARCHAR(16) NULL,
    father_name VARCHAR(100) NULL,
    business_address VARCHAR(500) NULL,
    postal_code_masked VARCHAR(16) NULL,
    -- Track page extras
    isic_code VARCHAR(32) NULL,
    isic_title VARCHAR(255) NULL,
    issue_type VARCHAR(64) NULL,
    expires_at DATETIME NULL,
    supervisory_authorities VARCHAR(500) NULL,
    -- Save/export tracking
    is_saved TINYINT(1) NOT NULL DEFAULT 0,
    saved_at DATETIME NULL,
    -- Source filter context (so we know which filter cached this row)
    filter_hash VARCHAR(64) NULL,
    -- Timestamps
    first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nl_request_number (request_number),
    INDEX idx_nl_status (status_slug),
    INDEX idx_nl_province (province_title),
    INDEX idx_nl_organization (organization_title),
    INDEX idx_nl_responded_at (responded_at),
    INDEX idx_nl_masked_national (masked_national_code),
    INDEX idx_nl_saved (is_saved),
    INDEX idx_nl_filter_hash (filter_hash),
    INDEX idx_nl_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS national_lookups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('province','township','main_organization','sub_organization') NOT NULL,
    code VARCHAR(32) NOT NULL,
    title VARCHAR(255) NOT NULL,
    parent_code VARCHAR(32) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    refreshed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lookups_kind_code (kind, code),
    INDEX idx_lookups_kind (kind),
    INDEX idx_lookups_parent (parent_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NEW in v3: sync_audit_log — golden-link audit trail (per docs/00-MASTER.md
-- §23.5). Every action the orchestrator takes is recorded here.
CREATE TABLE IF NOT EXISTS sync_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    national_request_number VARCHAR(64) NOT NULL,
    action VARCHAR(32) NOT NULL,
    outcome VARCHAR(32) NOT NULL,
    confidence INT NOT NULL DEFAULT 0,
    context JSON NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_decision VARCHAR(32) NULL,
    review_note VARCHAR(500) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sync_applicant (applicant_id),
    INDEX idx_sync_action (action, created_at),
    INDEX idx_sync_outcome (outcome, created_at),
    INDEX idx_sync_pending_review (reviewed_by, reviewed_at),
    INDEX idx_sync_confidence (confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Saved filters (admin-defined filter presets persisted across sessions).
CREATE TABLE IF NOT EXISTS national_filters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(100) NOT NULL,
    filter_data JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    last_result_count INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_filters_active (is_active),
    INDEX idx_filters_last_run (last_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CSV batch import jobs (bulk license-number lookups via the track page).
CREATE TABLE IF NOT EXISTS national_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_filename VARCHAR(255) NOT NULL,
    total_rows INT NOT NULL DEFAULT 0,
    processed_rows INT NOT NULL DEFAULT 0,
    matched_rows INT NOT NULL DEFAULT 0,
    csv_path VARCHAR(500) NULL,
    results_json LONGTEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    error TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_batches_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
