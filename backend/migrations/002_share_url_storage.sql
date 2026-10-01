ALTER TABLE share_links ADD COLUMN encrypted_secret VARBINARY(255) NULL AFTER secret_digest;
