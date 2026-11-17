-- ============================================================================
-- mojavez-v3 — Core module migration 003
-- rate_limit_buckets table for IpRateLimiter (HTTP rate limiting).
--
-- Per docs/00-MASTER.md §12.6 — IP-based rate limiting for sensitive HTTP
-- endpoints (user login, tracking status, license view, API). This table
-- persists hit timestamps per (bucket_key, ip) so limits survive logout
-- and session expiry.
--
-- Sliding-window implementation: hits_json is a JSON array of unix timestamps.
-- Old timestamps beyond the window are pruned on each hit. Stale buckets
-- (updated_at older than 2× window) are lazily pruned (1/50 hit probability).
-- ============================================================================

CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bucket_key VARCHAR(64) NOT NULL,             -- e.g. 'admin_login', 'tracking_search'
    ip VARCHAR(45) NOT NULL,                      -- IPv4 or IPv6
    hits_json TEXT NOT NULL DEFAULT '[]',         -- JSON array of unix timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rate_bucket (bucket_key, ip),
    INDEX idx_rate_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
