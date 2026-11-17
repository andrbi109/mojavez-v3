-- ============================================================================
-- mojavez-v3 — NationalGateway module migration 003
-- Make sync_audit_log.applicant_id + national_request_number nullable.
--
-- Per docs/00-MASTER.md §9.5 (orchestrator cycle-level logs) and §17.3
-- (migration markers): the sync_audit_log needs to record entries that are
-- NOT tied to a specific applicant — e.g.:
--   - 'cycle_started' / 'cycle_completed' (orchestrator cycle boundary).
--   - 'migrated' (data-migration marker — source='v2' or 'gateway').
--
-- Previously applicant_id was NOT NULL with a FK to applicants(id), which
-- made these cycle-level / migration markers impossible. This migration
-- drops the FK (keeps the index for fast applicant lookups) and makes
-- applicant_id + national_request_number NULLABLE so the orchestrator
-- can log cycle-level events and the migration scripts can log markers.
-- ============================================================================

ALTER TABLE sync_audit_log DROP FOREIGN KEY sync_audit_log_ibfk_1;
ALTER TABLE sync_audit_log MODIFY COLUMN applicant_id INT NULL;
ALTER TABLE sync_audit_log MODIFY COLUMN national_request_number VARCHAR(64) NULL;
