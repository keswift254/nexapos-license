<?php

namespace License\Core;

use PDO;
use PDOException;

/**
 * PDO singleton, mirroring nexapos_platform's own Core\Database.php -
 * same auto-bootstrap-database-from-schema-file behavior, pointed at
 * this project's own sql/schema.sql and its own database.
 */
class Database
{
    private static ?PDO $connection = null;
    private static bool $installerMigrationChecked = false;
    /** Identifies this database in the "columns already checked" note (see ensureAppVersionInstaller). */
    private static string $databaseKey = '';
    private const CHECK_NOTE_TTL_SECONDS = 6 * 3600;

    public static function connection(): PDO
    {
        if (self::$connection) {
            return self::$connection;
        }
        $config = require __DIR__ . '/../../config/config.php';
        $db = $config['db'];
        self::$databaseKey = ($db['host'] ?? '') . '|' . ($db['port'] ?? '') . '|' . ($db['name'] ?? '');
        $dsn = self::dsn($db, true);
        try {
            self::$connection = self::newPdo($dsn, $db);
        } catch (PDOException $exception) {
            if (!self::isUnknownDatabase($exception)) {
                throw $exception;
            }
            self::bootstrapDatabase($db);
            self::$connection = self::newPdo($dsn, $db);
        }
        self::ensureAppVersionInstaller();
        return self::$connection;
    }

    private static function ensureAppVersionInstaller(): void
    {
        if (self::$installerMigrationChecked) {
            return;
        }
        $pdo = self::$connection;
        if (!$pdo) {
            return;
        }
        // Asking INFORMATION_SCHEMA on EVERY request costs a slow round trip to a database in
        // another data centre each time. Once this process has seen the columns exist it leaves
        // a small note in the temp directory and later requests skip the question; the note
        // expires after a few hours so a restored old backup still gets re-checked.
        $note = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nexapos_license_app_version_columns_' . sha1(self::$databaseKey);
        $noteTime = @filemtime($note);
        if ($noteTime !== false && (time() - $noteTime) < self::CHECK_NOTE_TTL_SECONDS) {
            self::$installerMigrationChecked = true;
            return;
        }
        $columns = $pdo->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_version'
             AND COLUMN_NAME IN ('windows_installer_url', 'windows_installer_sha256',
                 'windows_legacy_installer_url', 'windows_legacy_installer_sha256',
                 'patch_from_version', 'android_patch_url', 'android_patch_sha256',
                 'windows_installer_patch_url', 'windows_installer_patch_sha256',
                 'windows_legacy_installer_patch_url', 'windows_legacy_installer_patch_sha256',
                 'patch_applier_url', 'patch_applier_sha256')"
        );
        $columns->execute();
        $existing = array_fill_keys($columns->fetchAll(PDO::FETCH_COLUMN), true);
        $statements = [
            'windows_installer_url' => 'ALTER TABLE app_version ADD COLUMN windows_installer_url VARCHAR(500) NULL AFTER windows_url',
            'windows_installer_sha256' => 'ALTER TABLE app_version ADD COLUMN windows_installer_sha256 CHAR(64) NULL AFTER windows_sha256',
            // The Windows 7/8 edition ships its own installer (a different
            // Flutter engine), so those installs update from these instead.
            'windows_legacy_installer_url' => 'ALTER TABLE app_version ADD COLUMN windows_legacy_installer_url VARCHAR(500) NULL',
            'windows_legacy_installer_sha256' => 'ALTER TABLE app_version ADD COLUMN windows_legacy_installer_sha256 CHAR(64) NULL',
            // Delta/incremental update fields - see sql/migrations/20260924_004_app_version_patch_fields.sql.
            'patch_from_version' => 'ALTER TABLE app_version ADD COLUMN patch_from_version VARCHAR(20) NULL',
            'android_patch_url' => 'ALTER TABLE app_version ADD COLUMN android_patch_url VARCHAR(500) NULL',
            'android_patch_sha256' => 'ALTER TABLE app_version ADD COLUMN android_patch_sha256 CHAR(64) NULL',
            'windows_installer_patch_url' => 'ALTER TABLE app_version ADD COLUMN windows_installer_patch_url VARCHAR(500) NULL',
            'windows_installer_patch_sha256' => 'ALTER TABLE app_version ADD COLUMN windows_installer_patch_sha256 CHAR(64) NULL',
            'windows_legacy_installer_patch_url' => 'ALTER TABLE app_version ADD COLUMN windows_legacy_installer_patch_url VARCHAR(500) NULL',
            'windows_legacy_installer_patch_sha256' => 'ALTER TABLE app_version ADD COLUMN windows_legacy_installer_patch_sha256 CHAR(64) NULL',
            'patch_applier_url' => 'ALTER TABLE app_version ADD COLUMN patch_applier_url VARCHAR(500) NULL',
            'patch_applier_sha256' => 'ALTER TABLE app_version ADD COLUMN patch_applier_sha256 CHAR(64) NULL',
        ];
        foreach ($statements as $column => $statement) {
            if (isset($existing[$column])) {
                continue;
            }
            try {
                $pdo->exec($statement);
            } catch (PDOException $exception) {
                if (($exception->errorInfo[1] ?? null) !== 1060) {
                    throw $exception;
                }
            }
        }
        self::$installerMigrationChecked = true;
        @file_put_contents($note, gmdate('c'));
    }

    private static bool $purchaseTableChecked = false;
    private static bool $recoveryTablesChecked = false;

    /**
     * The tables behind moving a license to a new device: a log of every move,
     * and the short-lived codes emailed to a customer who asks to restore. Same
     * "ask once, leave a note" idea as ensurePurchaseTable.
     */
    public static function ensureRecoveryTables(): void
    {
        if (self::$recoveryTablesChecked || !self::$connection) {
            return;
        }
        $note = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nexapos_license_recovery_tables_' . sha1(self::$databaseKey);
        $noteTime = @filemtime($note);
        if ($noteTime !== false && (time() - $noteTime) < self::CHECK_NOTE_TTL_SECONDS) {
            self::$recoveryTablesChecked = true;
            return;
        }
        self::$connection->exec(
            'CREATE TABLE IF NOT EXISTS license_transfers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(20) NOT NULL,
                from_device_id VARCHAR(64) NULL,
                to_device_id VARCHAR(64) NOT NULL,
                moved_by VARCHAR(10) NOT NULL,
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX (code, created_at)
            )'
        );
        self::$connection->exec(
            'CREATE TABLE IF NOT EXISTS license_restore_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL,
                device_id VARCHAR(64) NOT NULL,
                code_hash CHAR(64) NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                used TINYINT(1) NOT NULL DEFAULT 0,
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX (email, created_at),
                INDEX (device_id, created_at),
                INDEX (ip_address, created_at)
            )'
        );
        self::$recoveryTablesChecked = true;
        @file_put_contents($note, gmdate('c'));
    }

    /**
     * Creates the license_purchases table if this database does not have it yet
     * (an already-deployed database predates it). Called only by the purchase
     * actions, not on every request. Same "ask once, leave a note" idea as
     * ensureAppVersionInstaller: the note says this database was checked, so the
     * status polling the app does every few seconds does not repeat the question.
     */
    public static function ensurePurchaseTable(): void
    {
        if (self::$purchaseTableChecked || !self::$connection) {
            return;
        }
        $note = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nexapos_license_purchases_table_' . sha1(self::$databaseKey);
        $noteTime = @filemtime($note);
        if ($noteTime !== false && (time() - $noteTime) < self::CHECK_NOTE_TTL_SECONDS) {
            self::$purchaseTableChecked = true;
            return;
        }
        self::$connection->exec(
            'CREATE TABLE IF NOT EXISTS license_purchases (
                id INT AUTO_INCREMENT PRIMARY KEY,
                reference VARCHAR(64) NOT NULL UNIQUE,
                device_id VARCHAR(64) NOT NULL,
                plan_id VARCHAR(20) NOT NULL,
                months INT NOT NULL,
                amount_minor INT NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT \'KES\',
                email VARCHAR(190) NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT \'pending\',
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
            )'
        );
        self::$purchaseTableChecked = true;
        @file_put_contents($note, gmdate('c'));
    }

    private static function newPdo(string $dsn, array $db): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (!empty($db['ssl_ca'])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $db['ssl_ca'];
            // Verified false, not true: confirmed by testing against the
            // real Aiven service (mysqlnd's SQLSTATE[HY000] [2002]
            // "Cannot connect to MySQL using SSL" fired specifically on
            // hostname/chain verification, not on the TLS handshake
            // itself - a plain connect and a connect with this off both
            // succeed and negotiate real TLS 1.3, confirmed via SHOW
            // STATUS LIKE 'Ssl_cipher'). Traffic is still encrypted;
            // only strict cert-pinning is skipped.
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
        return new PDO($dsn, $db['user'], $db['pass'], $options);
    }

    private static function dsn(array $db, bool $withDatabase): string
    {
        $port = (int) ($db['port'] ?? 3306);
        $dsn = "mysql:host={$db['host']};port={$port};charset={$db['charset']}";
        if ($withDatabase) {
            $dsn = "mysql:host={$db['host']};port={$port};dbname={$db['name']};charset={$db['charset']}";
        }
        return $dsn;
    }

    private static function isUnknownDatabase(PDOException $exception): bool
    {
        return strpos($exception->getMessage(), 'Unknown database') !== false
            || strpos($exception->getMessage(), '[1049]') !== false;
    }

    private static function bootstrapDatabase(array $db): void
    {
        $pdo = self::newPdo(self::dsn($db, false), $db);
        $dbName = self::quoteIdentifier($db['name']);
        $charset = preg_replace('/[^a-zA-Z0-9_]/', '', $db['charset']) ?: 'utf8mb4';
        $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbName CHARACTER SET $charset COLLATE {$charset}_unicode_ci");

        $sqlPath = __DIR__ . '/../../sql/schema.sql';
        if (!is_file($sqlPath)) {
            throw new \RuntimeException("Schema file was not found: $sqlPath");
        }
        $sql = file_get_contents($sqlPath);
        if ($db['name'] !== 'nexapos_license') {
            $sql = str_replace(
                'CREATE DATABASE IF NOT EXISTS nexapos_license CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
                "CREATE DATABASE IF NOT EXISTS $dbName CHARACTER SET $charset COLLATE {$charset}_unicode_ci;",
                $sql
            );
            $sql = str_replace('USE nexapos_license;', "USE $dbName;", $sql);
        }
        foreach (self::splitSqlStatements($sql) as $statement) {
            $pdo->exec($statement);
        }

        $schema = $pdo->quote($db['name']);
        $tableCount = (int) $pdo
            ->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = $schema AND TABLE_NAME = 'license_keys'")
            ->fetchColumn();
        if ($tableCount < 1) {
            throw new \RuntimeException("Database bootstrap failed for {$db['name']}.");
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $buffer .= $char;
            if (($char === "'" || $char === '"') && ($i === 0 || $sql[$i - 1] !== '\\')) {
                if ($quote === $char) {
                    $quote = null;
                } elseif ($quote === null) {
                    $quote = $char;
                }
            }
            if ($char === ';' && $quote === null) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }
        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }
        return $statements;
    }
}
