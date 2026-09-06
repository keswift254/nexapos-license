-- Adds the optional single-file Windows installer without breaking the
-- existing ZIP field used by clients older than the installer-aware updater.
ALTER TABLE app_version
    ADD COLUMN windows_installer_url VARCHAR(500) NULL AFTER windows_url,
    ADD COLUMN windows_installer_sha256 CHAR(64) NULL AFTER windows_sha256;
