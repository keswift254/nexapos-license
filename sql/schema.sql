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
