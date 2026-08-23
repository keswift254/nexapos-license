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
CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    email VARCHAR(190) NOT NULL,
    business_name VARCHAR(160) NULL,
    phone VARCHAR(40) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (email)
);
