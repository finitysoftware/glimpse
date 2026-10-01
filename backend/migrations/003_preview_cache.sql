DELETE FROM api_cache;
ALTER TABLE api_cache MODIFY repository_id BIGINT UNSIGNED NULL, ADD scope_key BINARY(32) NOT NULL, ADD INDEX cache_scope (scope_key);
CREATE TABLE api_cache_state (
    id TINYINT UNSIGNED PRIMARY KEY,
    credential_digest BINARY(32) NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
    misses BIGINT UNSIGNED NOT NULL DEFAULT 0,
    upstream_calls BIGINT UNSIGNED NOT NULL DEFAULT 0,
    revalidations BIGINT UNSIGNED NOT NULL DEFAULT 0,
    evictions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rate_limit INT UNSIGNED NULL,
    rate_remaining INT UNSIGNED NULL,
    rate_reset BIGINT UNSIGNED NULL,
    rate_checked_at DATETIME(6) NULL,
    blocked_until DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
