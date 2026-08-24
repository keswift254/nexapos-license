CREATE DATABASE IF NOT EXISTS nexapos_license CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nexapos_license;

-- Deliberately NOT a permanent growing log of every key ever sold - a
-- code that's never activated is meant to be forgettable, not archived.
-- Once activated it becomes the durable "is this device licensed" record
-- (needed so the app's periodic background re-check and a later revoke
-- both have something to check against) - it's the activation itself
-- that's worth keeping, not the sale.
CREATE TABLE IF NOT EXISTS license_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_at TIMESTAMP NULL,
    device_id VARCHAR(64) NULL,
    activation_token_hash CHAR(64) NULL UNIQUE,
    -- How long the license itself stays valid once activated - chosen
    -- by the vendor at issue time (NULL = never expires, the original
    -- behavior). The countdown starts at activation, not issue, so a
    -- code sitting unredeemed doesn't burn into it - see valid_until.
    valid_days INT NULL,
    -- Computed once at activation as activated_at + valid_days, NULL if
    -- valid_days was NULL. Cached on the app itself (see
    -- nexapos_mobile's LicenseService) so it can be enforced fully
    -- offline using only the device's own clock - deliberately not
    -- something the app needs to be online to find out about, since the
    -- whole point is deactivating even if it never reaches this server
    -- again after activation.
    valid_until TIMESTAMP NULL,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    revoked_at TIMESTAMP NULL,
    INDEX (device_id)
);

-- Registrations from the marketing/download site - a lead, not a
-- customer yet. Purchase + key issuance still happen manually (the
-- vendor runs the key generator after payment clears, see
-- license_keys' own comment above) - this table exists purely so the
-- vendor knows who downloaded the app and how to follow up, not to gate
-- the download itself.
-- email is UNIQUE so a second registration attempt with the same
-- address is a clean, atomic duplicate rejection (catch the constraint
-- violation in register_lead) rather than a check-then-insert race.
CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    business_name VARCHAR(160) NULL,
    phone VARCHAR(40) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Single-row "what's the latest build" record for the app's in-app
-- update checker - the vendor publishes a new row (via generator.html)
-- each time a build ships; the app compares its own version against
-- this and offers to download+install if newer. id is always 1 - an
-- UPSERT via ON DUPLICATE KEY UPDATE keeps this genuinely a single
-- current row, not a growing history (nothing needs old versions once
-- superseded).
CREATE TABLE IF NOT EXISTS app_version (
    id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
    version VARCHAR(20) NOT NULL,
    windows_url VARCHAR(500) NOT NULL,
    android_url VARCHAR(500) NOT NULL,
    release_notes TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
