CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    password_changed_at DATETIME(6) NOT NULL,
    last_login_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY user_username (username),
    CONSTRAINT user_username_format CHECK (username REGEXP '^[a-z0-9_.-]{3,32}$'),
    CONSTRAINT user_session_version_positive CHECK (session_version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('member', 'platform_admin') NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (user_id, role),
    INDEX user_role_lookup (role, user_id),
    CONSTRAINT user_role_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE github_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    encrypted_token BLOB NOT NULL,
    encryption_key_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    credential_version INT UNSIGNED NOT NULL,
    github_user_id BIGINT UNSIGNED NULL,
    github_login VARCHAR(39) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_check_status ENUM('not_verified', 'connected', 'credentials_rejected', 'access_restricted', 'temporarily_unavailable') NOT NULL DEFAULT 'not_verified',
    last_error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_checked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY github_connection_user (user_id),
    UNIQUE KEY github_connection_owner (user_id, id),
    CONSTRAINT github_connection_owner_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT github_connection_version_positive CHECK (credential_version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cache_capacity_state (
    id TINYINT UNSIGNED PRIMARY KEY,
    reserved_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    reserved_entries INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT cache_capacity_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cache_capacity_state (id, reserved_bytes, reserved_entries, updated_at) VALUES (1, 0, 0, UTC_TIMESTAMP(6));

CREATE TABLE migration_checkpoints (
    name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    mode ENUM('legacy_owner', 'empty_install') NOT NULL,
    status ENUM('started', 'completed') NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    CONSTRAINT migration_checkpoint_owner FOREIGN KEY (owner_user_id) REFERENCES users(id),
    CONSTRAINT migration_checkpoint_mode_owner CHECK (mode = 'empty_install' OR owner_user_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sessions
    MODIFY kind ENUM('admin', 'visitor', 'user') NOT NULL,
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER kind,
    ADD COLUMN session_version INT UNSIGNED NULL AFTER user_id,
    ADD COLUMN audience ENUM('workspace', 'visitor', 'platform_admin') NULL AFTER session_version,
    ADD COLUMN revoked_at DATETIME(6) NULL AFTER expires_at,
    ADD INDEX session_owner (user_id, audience, expires_at),
    ADD CONSTRAINT session_owner_user FOREIGN KEY (user_id) REFERENCES users(id);

ALTER TABLE repositories
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
    ADD CONSTRAINT repository_owner_user FOREIGN KEY (user_id) REFERENCES users(id);

ALTER TABLE share_links
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
    ADD CONSTRAINT share_link_owner_user FOREIGN KEY (user_id) REFERENCES users(id);

ALTER TABLE api_cache
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER cache_key,
    ADD COLUMN credential_version INT UNSIGNED NULL AFTER user_id,
    ADD CONSTRAINT cache_owner_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE api_leases
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER slot,
    ADD COLUMN credential_version INT UNSIGNED NULL AFTER user_id,
    ADD INDEX lease_owner (user_id, expires_at),
    ADD CONSTRAINT lease_owner_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE audit_events
    ADD COLUMN actor_kind ENUM('user', 'visitor', 'cli', 'system', 'legacy') NULL AFTER outcome,
    ADD COLUMN actor_user_id BIGINT UNSIGNED NULL AFTER actor_kind,
    ADD COLUMN subject_user_id BIGINT UNSIGNED NULL AFTER actor_user_id,
    ADD COLUMN session_audience ENUM('workspace', 'visitor', 'platform_admin') NULL AFTER subject_user_id,
    ADD COLUMN request_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER session_audience,
    ADD COLUMN error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER request_id,
    ADD COLUMN metadata TEXT NULL AFTER share_link_id,
    ADD INDEX audit_subject (subject_user_id, created_at, id),
    ADD INDEX audit_actor (actor_user_id, created_at, id),
    ADD INDEX audit_action (action, created_at, id),
    ADD CONSTRAINT audit_actor_user FOREIGN KEY (actor_user_id) REFERENCES users(id),
    ADD CONSTRAINT audit_subject_user FOREIGN KEY (subject_user_id) REFERENCES users(id),
    ADD CONSTRAINT audit_metadata_json CHECK (metadata IS NULL OR (JSON_VALID(metadata) AND CHAR_LENGTH(metadata) <= 4096))
