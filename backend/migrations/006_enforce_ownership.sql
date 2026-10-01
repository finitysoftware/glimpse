ALTER TABLE repositories
    MODIFY user_id BIGINT UNSIGNED NOT NULL,
    DROP INDEX github_id,
    DROP INDEX repository_name,
    ADD UNIQUE KEY repository_owner_github (user_id, github_id),
    ADD UNIQUE KEY repository_owner_name (user_id, owner, name),
    ADD UNIQUE KEY repository_owner_id (user_id, id);

ALTER TABLE share_links
    MODIFY user_id BIGINT UNSIGNED NOT NULL,
    ADD UNIQUE KEY share_link_owner_id (user_id, id),
    ADD INDEX share_link_owner_repository (user_id, repository_id, id),
    ADD CONSTRAINT share_link_repository_owner FOREIGN KEY (user_id, repository_id) REFERENCES repositories(user_id, id);

DELETE FROM api_cache WHERE user_id IS NULL OR credential_version IS NULL;

ALTER TABLE api_cache
    MODIFY user_id BIGINT UNSIGNED NOT NULL,
    MODIFY credential_version INT UNSIGNED NOT NULL,
    DROP INDEX cache_created,
    DROP INDEX cache_scope,
    ADD INDEX cache_owner_expiry (user_id, expires_at),
    ADD INDEX cache_owner_created (user_id, created_at, cache_key),
    ADD INDEX cache_owner_scope (user_id, scope_key),
    ADD CONSTRAINT cache_repository_owner FOREIGN KEY (user_id, repository_id) REFERENCES repositories(user_id, id) ON DELETE CASCADE;

DELETE FROM api_cache_state;

ALTER TABLE api_cache_state
    DROP PRIMARY KEY,
    DROP COLUMN id,
    DROP COLUMN credential_digest,
    ADD COLUMN user_id BIGINT UNSIGNED NOT NULL FIRST,
    ADD COLUMN credential_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id,
    ADD PRIMARY KEY (user_id),
    ADD CONSTRAINT cache_state_owner_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

INSERT INTO api_cache_state (user_id, credential_version)
    SELECT u.id, COALESCE(c.credential_version, 0) FROM users u LEFT JOIN github_connections c ON c.user_id = u.id;

DELETE FROM api_leases WHERE user_id IS NULL OR credential_version IS NULL;

ALTER TABLE api_leases
    MODIFY user_id BIGINT UNSIGNED NOT NULL,
    MODIFY credential_version INT UNSIGNED NOT NULL;

DELETE FROM sessions WHERE kind = 'admin' OR audience IS NULL;

ALTER TABLE sessions
    ADD CONSTRAINT session_audience_rules CHECK (
        (kind = 'user' AND user_id IS NOT NULL AND session_version IS NOT NULL AND audience IN ('workspace', 'platform_admin'))
        OR (kind = 'visitor' AND user_id IS NULL AND session_version IS NULL AND audience = 'visitor'));

ALTER TABLE audit_events
    MODIFY actor_kind ENUM('user', 'visitor', 'cli', 'system', 'legacy') NOT NULL,
    ADD CONSTRAINT audit_user_actor_required CHECK (actor_kind <> 'user' OR actor_user_id IS NOT NULL),
    ADD CONSTRAINT audit_legacy_actor_unknown CHECK (actor_kind <> 'legacy' OR actor_user_id IS NULL)
