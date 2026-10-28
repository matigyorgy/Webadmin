-- Existing installations only. Back up first. Populate SSH fields before using nodes.
ALTER TABLE nodes
 ADD COLUMN ssh_host VARCHAR(255) NULL,
 ADD COLUMN ssh_port SMALLINT UNSIGNED NOT NULL DEFAULT 22,
 ADD COLUMN ssh_user VARCHAR(64) NULL,
 ADD COLUMN ssh_fingerprint VARCHAR(100) NULL,
 MODIFY COLUMN url VARCHAR(255) NULL,
 MODIFY COLUMN token VARCHAR(255) NULL;
-- Old HTTPS columns are retained for rollback; no longer read by the application.
