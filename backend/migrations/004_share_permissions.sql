ALTER TABLE share_links
    ADD COLUMN allowed_branches TEXT NULL,
    ADD COLUMN allow_history BOOLEAN NOT NULL DEFAULT TRUE;
