CREATE TABLE repositories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    github_id BIGINT UNSIGNED NOT NULL UNIQUE,
    owner VARCHAR(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    default_branch VARCHAR(255) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY repository_name (owner, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_digest BINARY(32) NOT NULL UNIQUE,
    kind ENUM('admin', 'visitor') NOT NULL,
    credential_digest BINARY(32) NULL,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    INDEX session_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE share_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARBINARY(32) NOT NULL UNIQUE,
    repository_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    secret_digest BINARY(32) NOT NULL UNIQUE,
    expires_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (repository_id) REFERENCES repositories(id),
    INDEX share_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE session_shares (
    session_id BIGINT UNSIGNED NOT NULL,
    share_link_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (session_id, share_link_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (share_link_id) REFERENCES share_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    repository_id BIGINT UNSIGNED NULL,
    share_link_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE SET NULL,
    FOREIGN KEY (share_link_id) REFERENCES share_links(id) ON DELETE SET NULL,
    INDEX audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_throttles (
    bucket_digest BINARY(32) PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    INDEX throttle_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_cache (
    cache_key BINARY(32) PRIMARY KEY,
    repository_id BIGINT UNSIGNED NOT NULL,
    etag VARBINARY(255) NULL,
    payload MEDIUMBLOB NOT NULL,
    payload_bytes INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE CASCADE,
    INDEX cache_expiry (expires_at),
    INDEX cache_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_leases (
    slot TINYINT UNSIGNED PRIMARY KEY,
    request_key BINARY(32) NOT NULL UNIQUE,
    owner_token BINARY(32) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    INDEX lease_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
