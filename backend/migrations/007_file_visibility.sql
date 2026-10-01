ALTER TABLE share_links
    ADD COLUMN visibility_mode ENUM('branch_policies', 'legacy_all_files') NOT NULL DEFAULT 'legacy_all_files' AFTER allow_history,
    ADD COLUMN visibility_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER visibility_mode,
    ADD COLUMN policy_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER visibility_schema_version,
    ADD CONSTRAINT share_link_policy_version_positive CHECK (policy_version >= 1);

ALTER TABLE share_links ALTER COLUMN visibility_mode SET DEFAULT 'branch_policies';

CREATE TABLE repository_visibility_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    repository_id BIGINT UNSIGNED NOT NULL,
    branch VARBINARY(255) NOT NULL,
    mode ENUM('selected', 'all') NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    source_sha CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    selected_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY visibility_profile_branch (user_id, repository_id, branch),
    UNIQUE KEY visibility_profile_owner (user_id, id),
    CONSTRAINT visibility_profile_repository FOREIGN KEY (user_id, repository_id) REFERENCES repositories(user_id, id) ON DELETE CASCADE,
    CONSTRAINT visibility_profile_version_positive CHECK (version >= 1),
    CONSTRAINT visibility_profile_sha_format CHECK (source_sha IS NULL OR source_sha REGEXP '^[a-f0-9]{40}$'),
    CONSTRAINT visibility_profile_all_count CHECK (mode = 'selected' OR selected_count = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE repository_visibility_files (
    profile_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    path VARBINARY(1024) NOT NULL,
    path_hash BINARY(32) NOT NULL,
    PRIMARY KEY (profile_id, path_hash),
    INDEX visibility_file_owner (user_id, profile_id),
    CONSTRAINT visibility_file_profile FOREIGN KEY (user_id, profile_id) REFERENCES repository_visibility_profiles(user_id, id) ON DELETE CASCADE,
    CONSTRAINT visibility_file_path_present CHECK (LENGTH(path) >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE share_branch_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    share_link_id BIGINT UNSIGNED NOT NULL,
    branch VARBINARY(255) NOT NULL,
    mode ENUM('selected', 'all') NOT NULL,
    source_profile_id BIGINT UNSIGNED NULL,
    source_profile_version INT UNSIGNED NULL,
    source_sha CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    selected_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY share_policy_branch (share_link_id, branch),
    UNIQUE KEY share_policy_owner (user_id, id),
    INDEX share_policy_link_owner (user_id, share_link_id),
    CONSTRAINT share_policy_link FOREIGN KEY (user_id, share_link_id) REFERENCES share_links(user_id, id) ON DELETE CASCADE,
    CONSTRAINT share_policy_source_profile FOREIGN KEY (source_profile_id) REFERENCES repository_visibility_profiles(id) ON DELETE SET NULL,
    CONSTRAINT share_policy_sha_format CHECK (source_sha IS NULL OR source_sha REGEXP '^[a-f0-9]{40}$'),
    CONSTRAINT share_policy_all_count CHECK (mode = 'selected' OR selected_count = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE share_visibility_files (
    policy_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    path VARBINARY(1024) NOT NULL,
    path_hash BINARY(32) NOT NULL,
    PRIMARY KEY (policy_id, path_hash),
    INDEX share_visibility_file_owner (user_id, policy_id),
    CONSTRAINT share_visibility_file_policy FOREIGN KEY (user_id, policy_id) REFERENCES share_branch_policies(user_id, id) ON DELETE CASCADE,
    CONSTRAINT share_visibility_file_path_present CHECK (LENGTH(path) >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
