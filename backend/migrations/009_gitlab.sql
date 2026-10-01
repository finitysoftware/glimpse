CREATE TABLE gitlab_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    base_url VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    encrypted_token BLOB NOT NULL,
    encryption_key_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    credential_version INT UNSIGNED NOT NULL,
    gitlab_user_id BIGINT UNSIGNED NULL,
    gitlab_username VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    token_expires_at DATE NULL,
    token_scopes VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_check_status ENUM('not_verified', 'connected', 'credentials_rejected', 'access_restricted', 'temporarily_unavailable') NOT NULL DEFAULT 'not_verified',
    last_error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_checked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY gitlab_connection_user (user_id),
    UNIQUE KEY gitlab_connection_owner (user_id, id),
    CONSTRAINT gitlab_connection_owner_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT gitlab_connection_version_positive CHECK (credential_version >= 1),
    CONSTRAINT gitlab_connection_https CHECK (base_url LIKE 'https://_%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE repositories
    ADD COLUMN provider ENUM('github', 'gitlab') NOT NULL DEFAULT 'github' AFTER user_id,
    ADD COLUMN instance_url VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'https://github.com' AFTER provider,
    CHANGE COLUMN github_id external_id BIGINT UNSIGNED NOT NULL,
    MODIFY owner VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    MODIFY name VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    DROP INDEX repository_owner_github,
    DROP INDEX repository_owner_name,
    ADD UNIQUE KEY repository_owner_external (user_id, provider, instance_url, external_id),
    ADD UNIQUE KEY repository_owner_path (user_id, provider, instance_url, owner, name);

DELETE FROM api_cache;

UPDATE cache_capacity_state SET reserved_bytes = 0, reserved_entries = 0, updated_at = UTC_TIMESTAMP(6) WHERE id = 1;

ALTER TABLE api_cache
    ADD COLUMN provider ENUM('github', 'gitlab') NOT NULL DEFAULT 'github' AFTER user_id,
    ADD INDEX cache_owner_provider_created (user_id, provider, created_at, cache_key),
    DROP INDEX cache_owner_created;

UPDATE api_cache_state SET revision = revision + 1;

ALTER TABLE api_cache_state
    ADD COLUMN provider ENUM('github', 'gitlab') NOT NULL DEFAULT 'github' AFTER user_id,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (user_id, provider)
