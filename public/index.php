<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'License\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

use License\Core\Database;
use License\Core\LicenseCode;

function jsonResponse(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function requestBody(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

/**
 * Same case-insensitive-header gotcha nexapos_platform's Auth class
 * already hit and documented: $_SERVER['HTTP_AUTHORIZATION'] is unset
 * under some Apache/PHP configs, and a literal getallheaders() lookup
 * misses lowercase header names some clients send.
 */
function headerValue(string $name): string
{
    $server = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$server])) {
        return (string) $_SERVER[$server];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return (string) $value;
            }
        }
    }
    return '';
}

function requireAdmin(array $licenseConfig): void
{
    $secret = trim(headerValue('X-Admin-Secret'));
    $expected = (string) $licenseConfig['admin_secret'];
    if ($expected === '' || !hash_equals($expected, $secret)) {
        jsonResponse(['success' => false, 'message' => 'Invalid or missing admin secret.'], 401);
    }
}

set_exception_handler(function (\Throwable $e): void {
    error_log('[nexapos_license] ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Server error.'], 500);
});

$pdo = Database::connection();
$licenseConfig = require __DIR__ . '/../config/license.php';
$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// Render's health check hits this - deliberately goes through
// Database::connection() above rather than skipping it, so a deploy
// only reports healthy once the DB is actually reachable, not just PHP.
if ($action === 'health' && $method === 'GET') {
    jsonResponse(['success' => true, 'service' => 'nexapos_license']);
}

/**
 * Only the key generator tool (run by whoever holds admin_secret) calls
 * this - once per sale, right after payment clears. Deliberately no
 * customer-identifying info accepted or stored here (see schema.sql's
 * comment) - just "a key now exists, redeemable for the next N minutes".
 */
if ($action === 'issue' && $method === 'POST') {
    requireAdmin($licenseConfig);

    $code = LicenseCode::generate($pdo);
    $expiryMinutes = (int) $licenseConfig['key_expiry_minutes'];
    $insert = $pdo->prepare('INSERT INTO license_keys (code, expires_at) VALUES (?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE))');
    $insert->execute([$code, $expiryMinutes]);

    $expiresAt = $pdo->prepare('SELECT expires_at FROM license_keys WHERE code = ?');
    $expiresAt->execute([$code]);

    jsonResponse(['success' => true, 'code' => $code, 'expires_at' => $expiresAt->fetchColumn()]);
}

/**
 * The app calls this once, the first time a key is entered. Atomic
 * claim (single UPDATE with the ownership check built into its WHERE
 * clause) so two devices racing to redeem the same code can never both
 * win - matches join_shop's same pattern in nexapos_platform. Allowing
 * device_id to already equal the caller's own is what makes this
 * idempotent (a reinstall on the same device just gets a fresh token,
 * rather than being told the code is "already used").
 */
if ($action === 'activate' && $method === 'POST') {
    $body = requestBody();
    $code = strtoupper(trim((string) ($body['code'] ?? '')));
    $deviceId = trim((string) ($body['device_id'] ?? ''));
    if ($code === '' || $deviceId === '') {
        jsonResponse(['success' => false, 'message' => 'code and device_id are required.'], 422);
    }

    $existing = $pdo->prepare('SELECT * FROM license_keys WHERE code = ?');
    $existing->execute([$code]);
    $row = $existing->fetch();
    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'Invalid license key.'], 422);
    }
    if ((int) $row['revoked'] === 1) {
        jsonResponse(['success' => false, 'message' => 'This license key has been revoked.'], 422);
    }
    if ($row['device_id'] !== null && $row['device_id'] !== $deviceId) {
        jsonResponse(['success' => false, 'message' => 'This license key is already activated on another device.'], 422);
    }
    // ' UTC' is load-bearing: license_keys.expires_at is written via
    // MySQL's UTC_TIMESTAMP(), but this server's PHP default timezone is
    // NOT UTC (Europe/Berlin) - without forcing the zone, strtotime()
    // parses the DB's UTC clock-time string as if it were already local
    // time, shifting the computed epoch hours off and making a freshly
    // issued code appear expired immediately. Confirmed by testing, not
    // guessed - this exact bug fired on the very first activate() call.
    if ($row['device_id'] === null && strtotime((string) $row['expires_at'] . ' UTC') < time()) {
        jsonResponse(['success' => false, 'message' => 'This license key has expired. Ask for a new one.'], 422);
    }

    $claim = $pdo->prepare('
        UPDATE license_keys
        SET device_id = ?, activated_at = UTC_TIMESTAMP()
        WHERE code = ? AND revoked = 0 AND (device_id = ? OR (device_id IS NULL AND expires_at > UTC_TIMESTAMP()))
    ');
    $claim->execute([$deviceId, $code, $deviceId]);
    if ($claim->rowCount() !== 1) {
        jsonResponse(['success' => false, 'message' => 'Could not activate this license key. It may have just been claimed by another device.'], 409);
    }

    $activationToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $activationToken);
    $store = $pdo->prepare('UPDATE license_keys SET activation_token_hash = ? WHERE code = ?');
    $store->execute([$tokenHash, $code]);

    jsonResponse(['success' => true, 'activation_token' => $activationToken]);
}

/**
 * Called once at activation to cache locally, then again only whenever
 * the app happens to have internet - a background health check, never
 * something checkout blocks on. "valid: false" means revoked; anything
 * else (unreachable, timeout) the app already treats as "couldn't check,
 * keep running" on the caller's side, not this endpoint's concern.
 */
if ($action === 'verify' && $method === 'POST') {
    $token = trim(headerValue('Authorization'));
    if (stripos($token, 'Bearer ') === 0) {
        $token = trim(substr($token, 7));
    }
    if ($token === '') {
        jsonResponse(['success' => false, 'valid' => false, 'message' => 'Missing activation token.'], 401);
    }

    $stmt = $pdo->prepare('SELECT revoked FROM license_keys WHERE activation_token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonResponse(['success' => true, 'valid' => false]);
    }
    jsonResponse(['success' => true, 'valid' => (int) $row['revoked'] === 0]);
}

/** Admin action - the seller revoking a key after a refund/chargeback. */
if ($action === 'revoke' && $method === 'POST') {
    requireAdmin($licenseConfig);
    $body = requestBody();
    $code = strtoupper(trim((string) ($body['code'] ?? '')));
    if ($code === '') {
        jsonResponse(['success' => false, 'message' => 'code is required.'], 422);
    }
    $revoke = $pdo->prepare('UPDATE license_keys SET revoked = 1, revoked_at = UTC_TIMESTAMP() WHERE code = ?');
    $revoke->execute([$code]);
    jsonResponse(['success' => true, 'revoked' => $revoke->rowCount() === 1]);
}

jsonResponse(['success' => false, 'message' => 'Unknown action.'], 404);
