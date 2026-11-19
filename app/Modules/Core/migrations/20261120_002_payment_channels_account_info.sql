-- ============================================================================
-- mojavez-v3 — Core module migration 017 (Phase 57b)
--
-- Adds an `account_info` TEXT NULL column to the `payment_channels` table so
-- the admin can store a free-form description of each channel — e.g. the
-- card number, IBAN, account holder, or redirect gateway URL — and the user
-- can see those details in the payment modal before choosing how to pay.
--
-- Until now the `payment_channels` table only carried `id, title,
-- channel_type, is_active, sort_order` (see migration
-- 20261117_003). The user-side payment modal in user/payments.php could
-- only render a disabled "پرداخت آنلاین (به‌زودی)" placeholder — the actual
-- active channels were never offered. Phase 57b closes that gap:
--   1. admin saves account_info via /admin/payment-channels/save
--      (PaymentController::saveChannel)
--   2. UserPanelController::payments() loads active channels and passes
--      them to the view
--   3. user/payments.php shows every active channel as a radio option with
--      its title / type / account_info inline.
--
-- The column is nullable so existing rows (and any channels created
-- without account_info) stay valid.
--
-- Idempotent ALTER (information_schema guard) — MariaDB does not support
-- `ADD COLUMN IF NOT EXISTS`, so we guard the DDL with a prepared
-- statement switch (same pattern as migration 20261119_001).
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_channels'
      AND COLUMN_NAME = 'account_info'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `payment_channels` ADD COLUMN `account_info` TEXT NULL DEFAULT NULL AFTER `channel_type`',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
