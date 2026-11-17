-- ============================================================================
-- mojavez-v3 — NationalGateway module migration 004
-- integration_log table — the two-way sync audit trail (Phase 8, Rollout Stage 2).
--
-- Per docs/00-MASTER.md §29.1 (Stage 2) + §19.8 (post-v1.0.0 items):
--   "audit کامل در integration_log با direction='inbound' و 'outbound'."
--
-- This table is the canonical record of EVERY interaction between v3 and
-- the upstream national gateway — both reads (inbound, Stage 1) and writes
-- (outbound, Stage 2). It complements sync_audit_log (which records match
-- decisions, not raw interactions).
--
-- Schema design (per §29.1 + §4.4 idempotency):
--   - direction ENUM('inbound','outbound') — read from / written to upstream.
--   - operation VARCHAR — 'fetch_license' | 'push_status' | 'push_document' |
--     'request_match' | 'fetch_lookup' | etc.
--   - idempotency_key VARCHAR(128) UNIQUE — dedup key for retries (per §4.4).
--     For inbound fetches, derived from (request_number, fetched_at_bucket).
--     For outbound pushes, derived from (applicant_id, operation, payload_hash).
--   - request_payload_json + response_payload_json — full bidirectional audit.
--   - http_status SMALLINT — 2xx = success, 4xx = client error, 5xx = server.
--   - source_of_truth ENUM('v3','upstream') — per §29.1 "source-of-truth for
--     each field". When a conflict is detected, this records which side won.
--   - conflict_resolution VARCHAR — 'last_write_wins' | 'v3_wins' |
--     'upstream_wins' | 'manual_review' | null (no conflict).
--   - upstream_updated_at DATETIME — the upstream's updated_at timestamp
--     (for last-write-wins comparison).
--   - latency_ms INT — for SLA monitoring + circuit-breaker analytics.
--
-- Storage: same charset/collation as the rest of the schema (utf8mb4_unicode_ci).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `integration_log` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `direction` ENUM('inbound','outbound') NOT NULL,
    `operation` VARCHAR(64) NOT NULL COMMENT 'fetch_license|push_status|push_document|request_match|fetch_lookup',
    `applicant_id` INT DEFAULT NULL,
    `national_request_number` VARCHAR(64) DEFAULT NULL,
    `idempotency_key` VARCHAR(128) NOT NULL,
    `request_payload_json` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_payload_json`)),
    `response_payload_json` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_payload_json`)),
    `http_status` SMALLINT DEFAULT NULL COMMENT 'HTTP status code from upstream (2xx/4xx/5xx) or NULL if no HTTP (e.g. local-only)',
    `outcome` VARCHAR(32) NOT NULL DEFAULT 'success' COMMENT 'success|client_error|server_error|timeout|conflict|skipped',
    `source_of_truth` ENUM('v3','upstream') DEFAULT NULL COMMENT 'per §29.1: which side won when a field conflict occurred',
    `conflict_resolution` VARCHAR(32) DEFAULT NULL COMMENT 'last_write_wins|v3_wins|upstream_wins|manual_review',
    `upstream_updated_at` DATETIME DEFAULT NULL COMMENT 'upstream record updated_at (for last-write-wins comparison)',
    `latency_ms` INT DEFAULT NULL COMMENT 'round-trip latency in milliseconds',
    `error_message` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_integration_idempotency` (`idempotency_key`),
    KEY `idx_integration_direction` (`direction`, `created_at`),
    KEY `idx_integration_operation` (`operation`, `created_at`),
    KEY `idx_integration_applicant` (`applicant_id`),
    KEY `idx_integration_request_num` (`national_request_number`),
    KEY `idx_integration_outcome` (`outcome`, `created_at`),
    KEY `idx_integration_conflict` (`conflict_resolution`),
    CONSTRAINT `integration_log_ibfk_1` FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
