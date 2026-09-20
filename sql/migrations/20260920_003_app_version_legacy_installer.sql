-- The Windows 7/8 edition of the desktop app is built on a different Flutter
-- engine, so it has its own installer and must update from it (not from the
-- Windows 10/11 installer, which cannot start on those systems). Optional:
-- NULL means "no Windows 7/8 build of this version". The server also creates
-- these columns itself on first use (Database::ensureAppVersionInstaller).
ALTER TABLE app_version
    ADD COLUMN windows_legacy_installer_url VARCHAR(500) NULL,
    ADD COLUMN windows_legacy_installer_sha256 CHAR(64) NULL;
