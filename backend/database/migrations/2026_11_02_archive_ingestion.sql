-- Additive migration for Internet Archive ingestion.
-- Safe to run more than once. It does not drop or rewrite existing rows.

ALTER TABLE programs
  ADD COLUMN IF NOT EXISTS license_label varchar(255) NULL AFTER source_url,
  ADD COLUMN IF NOT EXISTS license_url text NULL AFTER license_label,
  ADD COLUMN IF NOT EXISTS last_imported_at datetime NULL AFTER updated_at;

ALTER TABLE media_files
  ADD COLUMN IF NOT EXISTS supports_byte_ranges tinyint(1) NULL AFTER validation_status,
  ADD COLUMN IF NOT EXISTS validation_notes text NULL AFTER supports_byte_ranges;

CREATE TABLE IF NOT EXISTS import_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  archive_identifier varchar(255) NOT NULL,
  outcome varchar(30) NOT NULL,
  message text NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_import_events_identifier (archive_identifier, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
