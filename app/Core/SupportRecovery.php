<?php
declare(strict_types=1);
namespace License\Core;

use PDO;

final class SupportRecovery
{
    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS device_security_recovery (
            device_id VARCHAR(190) PRIMARY KEY,
            authenticator_generation BIGINT NOT NULL DEFAULT 0,
            access_hash CHAR(64) NULL,
            access_expires_at DATETIME NULL,
            access_used_at DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )');
    }

    public static function generation(PDO $pdo, string $deviceId): int
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare('SELECT authenticator_generation FROM device_security_recovery WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        return (int) $stmt->fetchColumn();
    }
}
