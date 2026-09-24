-- Delta/incremental update support: a device already on patch_from_version
-- can download a small binary patch instead of the full install. Optional -
-- NULL means "no patch published for this release". The server also creates
-- these columns itself on first use (Database::ensureAppVersionInstaller).
ALTER TABLE app_version
    ADD COLUMN patch_from_version VARCHAR(20) NULL,
    ADD COLUMN android_patch_url VARCHAR(500) NULL,
    ADD COLUMN android_patch_sha256 CHAR(64) NULL,
    ADD COLUMN windows_installer_patch_url VARCHAR(500) NULL,
    ADD COLUMN windows_installer_patch_sha256 CHAR(64) NULL,
    ADD COLUMN windows_legacy_installer_patch_url VARCHAR(500) NULL,
    ADD COLUMN windows_legacy_installer_patch_sha256 CHAR(64) NULL,
    ADD COLUMN patch_applier_url VARCHAR(500) NULL,
    ADD COLUMN patch_applier_sha256 CHAR(64) NULL;
