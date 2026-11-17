-- ============================================================================
-- mojavez-v3 — database/install.sql
-- ============================================================================
-- Fresh-install entry point. Creates the mojavez_v3 database (if missing)
-- and the mojavez user (if missing), then sources schema.sql.
--
-- Usage (as DB root or with CREATE privilege):
--   mariadb -u root -p < database/install.sql
-- Or from inside MariaDB:
--   SOURCE database/install.sql;
--
-- For shared hosting where you can't CREATE DATABASE, skip this file and
-- import schema.sql directly into your pre-created DB via phpMyAdmin.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS mojavez_v3
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Create the app user (no-op if it exists — adjust password as needed).
CREATE USER IF NOT EXISTS 'mojavez'@'localhost' IDENTIFIED BY 'mojavez_pass';
GRANT ALL PRIVILEGES ON mojavez_v3.* TO 'mojavez'@'localhost';
FLUSH PRIVILEGES;

USE mojavez_v3;
SOURCE schema.sql;
