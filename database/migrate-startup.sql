-- Old installations with none of these columns: run once. Prefer the idempotent admin upgrade button.
ALTER TABLE servers
 ADD COLUMN startup_command TEXT NULL,
 ADD COLUMN install_source VARCHAR(255) NULL,
 ADD COLUMN install_folder VARCHAR(120) NULL,
 ADD COLUMN provision_job VARCHAR(32) NULL,
 ADD COLUMN provision_state VARCHAR(16) NOT NULL DEFAULT 'ready',
 ADD COLUMN provision_message VARCHAR(500) NULL;
