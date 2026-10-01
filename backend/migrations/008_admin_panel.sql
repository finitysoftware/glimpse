ALTER TABLE users
    ADD COLUMN first_name VARCHAR(64) NULL AFTER username,
    ADD COLUMN last_name VARCHAR(64) NULL AFTER first_name,
    ADD COLUMN email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER last_name,
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER session_version,
    ADD COLUMN must_change_password BOOLEAN NOT NULL DEFAULT FALSE AFTER password_changed_at,
    ADD UNIQUE KEY user_email (email),
    ADD INDEX user_status_login (status, last_login_at),
    ADD INDEX user_full_name (last_name, first_name),
    ADD CONSTRAINT user_version_positive CHECK (version >= 1),
    ADD CONSTRAINT user_email_format CHECK (email IS NULL OR (email = LOWER(email) AND email LIKE '_%@_%'));

ALTER TABLE migration_checkpoints DROP FOREIGN KEY migration_checkpoint_owner;

ALTER TABLE migration_checkpoints DROP CONSTRAINT migration_checkpoint_mode_owner;

ALTER TABLE migration_checkpoints
    ADD CONSTRAINT migration_checkpoint_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE daily_metrics (
    day DATE NOT NULL,
    metric ENUM('workspace_login', 'admin_login', 'link_opened', 'link_created') NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, metric, user_id),
    INDEX daily_metrics_by_user (user_id, day),
    CONSTRAINT daily_metric_owner FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
