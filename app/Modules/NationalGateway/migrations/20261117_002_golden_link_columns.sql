-- ============================================================================
-- mojavez-v3 — NationalGateway module migration 002
-- Add golden-link match columns to national_licenses.
--
-- These columns are populated by NationalSyncOrchestrator after a successful
-- PatternMatcher match. They're the "golden link" — the foreign-key-like
-- relationship from a national license to the local applicant it matches.
-- ============================================================================

ALTER TABLE national_licenses
    ADD COLUMN matched_applicant_id INT NULL,
    ADD COLUMN match_confidence INT NULL,
    ADD COLUMN matched_by VARCHAR(32) NULL,
    ADD COLUMN matched_at DATETIME NULL;

-- Index for finding unmatched licenses (the orchestrator's WHERE clause).
ALTER TABLE national_licenses
    ADD INDEX idx_nl_matched_applicant (matched_applicant_id),
    ADD INDEX idx_nl_matched_confidence (match_confidence);
