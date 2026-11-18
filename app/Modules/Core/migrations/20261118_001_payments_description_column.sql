-- ============================================================================
-- mojavez-v3 — Core module migration 016 (Phase 50, v2.1.6)
--
-- Adds a `description` column to the `payments` table so that an admin
-- raising a payment request on behalf of an applicant (AdminController
-- ::requestPayment) can attach a free-form note that the user then sees
-- in their payments list (user/payments.php shows `purpose` if non-empty
-- and falls back to `description`).
--
-- The column is nullable and defaults to NULL so existing rows (and any
-- payments created via the registration-wizard or license-reissue flow,
-- which never set a description) are unaffected.
-- ============================================================================

ALTER TABLE payments
    ADD COLUMN description VARCHAR(500) NULL DEFAULT NULL AFTER reference_code;
