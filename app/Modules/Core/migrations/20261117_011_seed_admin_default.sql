-- ============================================================================
-- mojavez-v3 — Core module migration 011
-- Seed the default admin user so a fresh install is immediately usable.
--
-- Phase 16 (v1.7.0): the admin panel (`/admin/*`) is gated by
-- `requireRole(['admin'])`, which reads the `users` table. A fresh
-- install had NO users at all — so the first person to land on
-- `/admin/login` could not log in (no account existed), and the only
-- way to create one was to run a raw `INSERT` against the DB. This
-- migration seeds a single default admin so the system is usable
-- immediately after `scripts/migrate.php`.
--
-- Credentials:
--   username: admin
--   password: admin123   ← change immediately on first login!
--
-- The password hash below is `password_hash('admin123', PASSWORD_BCRYPT)`
-- (cost 12). The plaintext `admin123` is intentionally weak — the
-- admin must change it before exposing the system to anything other
-- than a local dev sandbox. The `display_name` is Persian
-- ('مدیر کل' = 'Head Administrator').
--
-- Idempotency: `INSERT ... SELECT ... WHERE NOT EXISTS (SELECT 1 FROM
-- users WHERE username='admin')` — re-running against a DB that
-- already has the admin row (or any row with username='admin') is a
-- no-op. This protects against the migration clobbering an admin who
-- has already changed their password: if the admin row exists at all,
-- the seed skips it (even if the password has been rotated).
-- ============================================================================

INSERT INTO `users` (`username`, `password`, `display_name`, `role`, `created_at`)
SELECT
    'admin',
    '$2y$12$Wgb0mwSnZKV/T7cAM4rvsepvrafAPzd8cg1hIGL5/deYfDpaIum6y',
    'مدیر کل',
    'admin',
    NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'admin');
