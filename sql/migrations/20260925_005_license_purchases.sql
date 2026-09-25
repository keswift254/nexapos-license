-- Selling licenses from the activation screen: one row per purchase attempt.
-- Also created automatically the first time a purchase action runs (see
-- Database::ensurePurchaseTable), so an already-deployed database needs no manual
-- step; this file is the same statement for anyone applying migrations by hand.
CREATE TABLE IF NOT EXISTS license_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(64) NOT NULL UNIQUE,
    device_id VARCHAR(64) NOT NULL,
    plan_id VARCHAR(20) NOT NULL,
    months INT NOT NULL,
    amount_minor INT NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    email VARCHAR(190) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',
    paystack_status VARCHAR(40) NULL,
    authorization_url VARCHAR(500) NULL,
    license_code VARCHAR(20) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_checked_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    issued_at TIMESTAMP NULL,
    INDEX (device_id, created_at),
    INDEX (ip_address, created_at),
    INDEX (status, created_at)
);
