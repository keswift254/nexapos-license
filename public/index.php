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
use License\Services\Mailer;

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

// register_lead is the only action a browser calls cross-origin (from
// the registration site, a different host) - activate/verify come from
// the Flutter app (not subject to CORS) and issue/revoke/list_leads
// require the admin secret regardless of origin, so a permissive
// header here doesn't expose anything a stolen header wouldn't already.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Secret');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Render's health check hits this - deliberately goes through
// Database::connection() above rather than skipping it, so a deploy
// only reports healthy once the DB is actually reachable, not just PHP.
if ($action === 'health' && $method === 'GET') {
    jsonResponse(['success' => true, 'service' => 'nexapos_license']);
}

/**
 * Public registration from the marketing/download site - a lead, not a
 * gate. See leads' own schema comment: purchase and key issuance stay
 * manual, this just tells the vendor who downloaded and how to follow
 * up with them.
 */
if ($action === 'register_lead' && $method === 'POST') {
    $body = requestBody();
    $name = trim((string) ($body['name'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $businessName = trim((string) ($body['business_name'] ?? ''));
    $phone = trim((string) ($body['phone'] ?? ''));
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'A valid name and email are required.'], 422);
    }

    // leads.email is UNIQUE - catching the constraint violation is the
    // atomic version of this check (no separate SELECT-then-INSERT race
    // between two near-simultaneous submissions of the same address).
    try {
        $insert = $pdo->prepare('INSERT INTO leads (name, email, business_name, phone) VALUES (?, ?, ?, ?)');
        $insert->execute([$name, $email, $businessName !== '' ? $businessName : null, $phone !== '' ? $phone : null]);
    } catch (\PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            jsonResponse(['success' => false, 'message' => 'This email is already registered. Check your inbox, or contact us on WhatsApp if you need help.'], 409);
        }
        throw $e;
    }

    // The lead is already saved above - a mail failure (unconfigured
    // SMTP, a bad password, the server being unreachable) must never
    // turn into a failed registration response for the customer. Two
    // separate sends (vendor notification + customer welcome) so one
    // failing doesn't take the other down with it.
    try {
        $mailer = new Mailer($licenseConfig);
        $mailer->send(
            (string) $licenseConfig['notify_email'],
            "New NexaPOS registration: $name",
            "Name: $name\nEmail: $email\nBusiness: " . ($businessName !== '' ? $businessName : '-') .
                "\nPhone: " . ($phone !== '' ? $phone : '-') . "\n\nRegistered at (UTC): " . gmdate('Y-m-d H:i:s')
        );
    } catch (\Throwable $e) {
        error_log('[nexapos_license] Could not send registration notification: ' . $e->getMessage());
    }
    try {
        $mailer = new Mailer($licenseConfig);
        $mailer->send(
            $email,
            'Welcome to NexaPOS - next steps',
            "Hi $name,\n\n" .
                "Thanks for registering for NexaPOS - offline-first point of sale for your business.\n\n" .
                "Next steps:\n" .
                "1. Download the app for your device: https://keswift254.github.io/nexapos-site/#get-started\n" .
                "2. Install it and open it - you'll land on an activation screen.\n" .
                "3. Reply to this email or message us on WhatsApp to arrange payment: https://wa.me/message/M5SGWZ664XJ4C1\n" .
                "4. We'll generate your license key and send it over - enter it once, and the app runs fully offline after that, no internet needed.\n\n" .
                "Questions? Just reply to this email or message us on WhatsApp.\n\n" .
                '- The NexaPOS team'
        );
    } catch (\Throwable $e) {
        error_log('[nexapos_license] Could not send welcome email: ' . $e->getMessage());
    }

    jsonResponse(['success' => true]);
}

/** Admin action - see who has registered so far. */
if ($action === 'list_leads' && $method === 'GET') {
    requireAdmin($licenseConfig);
    $rows = $pdo->query('SELECT id, name, email, business_name, phone, created_at FROM leads ORDER BY id DESC')->fetchAll();
    jsonResponse(['success' => true, 'leads' => $rows]);
}

/**
 * Only the key generator tool (run by whoever holds admin_secret) calls
 * this - once per sale, right after payment clears. Deliberately no
 * customer-identifying info accepted or stored here (see schema.sql's
 * comment) - just "a key now exists, redeemable for the next N minutes".
 * expiry_minutes is optional per-call override of the config default
 * (e.g. a longer window for "I'll install it tomorrow") - clamped to
 * 1 minute..7 days so a fat-fingered value can't create a code that
 * lingers unactivated for months, working against the "not a permanent
 * growing database" design (see schema.sql's comment on license_keys).
 * license_duration_days is a separate thing entirely - how long the
 * license stays valid AFTER activation (see valid_days/valid_until in
 * schema.sql) - omitted/0 means it never expires.
 */
if ($action === 'issue' && $method === 'POST') {
    requireAdmin($licenseConfig);

    $body = requestBody();
    $expiryMinutes = isset($body['expiry_minutes']) && $body['expiry_minutes'] !== ''
        ? (int) $body['expiry_minutes']
        : (int) $licenseConfig['key_expiry_minutes'];
    $expiryMinutes = max(1, min($expiryMinutes, 10080));

    $validDays = isset($body['license_duration_days']) && $body['license_duration_days'] !== ''
        ? (int) $body['license_duration_days']
        : null;
    if ($validDays !== null && $validDays < 1) {
        $validDays = null;
    }

    $code = LicenseCode::generate($pdo);
    $insert = $pdo->prepare('INSERT INTO license_keys (code, expires_at, valid_days) VALUES (?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), ?)');
    $insert->execute([$code, $expiryMinutes, $validDays]);

    $issued = $pdo->prepare('SELECT expires_at FROM license_keys WHERE code = ?');
    $issued->execute([$code]);

    jsonResponse(['success' => true, 'code' => $code, 'expires_at' => $issued->fetchColumn(), 'valid_days' => $validDays]);
}

/**
 * The app calls this once, the first time a key is entered. One-time
 * use, full stop - not even the same device that already claimed it
 * can redeem it again. A legitimate reinstall needs a fresh code from
 * the vendor, same as any other support request in this manually-run
 * model. Atomic claim (single UPDATE with the ownership check built
 * into its WHERE clause) so two requests racing for the same
 * still-unclaimed code can never both win - matches join_shop's same
 * pattern in nexapos_platform; the upfront SELECT-based checks below
 * exist only to give a precise error message in the common case, not
 * to guard the race (the UPDATE's own WHERE clause does that).
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
    if ($row['device_id'] !== null) {
        jsonResponse(['success' => false, 'message' => 'This license key has already been used.'], 422);
    }
    // ' UTC' is load-bearing: license_keys.expires_at is written via
    // MySQL's UTC_TIMESTAMP(), but this server's PHP default timezone is
    // NOT UTC (Europe/Berlin) - without forcing the zone, strtotime()
    // parses the DB's UTC clock-time string as if it were already local
    // time, shifting the computed epoch hours off and making a freshly
    // issued code appear expired immediately. Confirmed by testing, not
    // guessed - this exact bug fired on the very first activate() call.
    if (strtotime((string) $row['expires_at'] . ' UTC') < time()) {
        jsonResponse(['success' => false, 'message' => 'This license key has expired. Ask for a new one.'], 422);
    }

    $claim = $pdo->prepare('
        UPDATE license_keys
        SET device_id = ?, activated_at = UTC_TIMESTAMP()
        WHERE code = ? AND revoked = 0 AND device_id IS NULL AND expires_at > UTC_TIMESTAMP()
    ');
    $claim->execute([$deviceId, $code]);
    if ($claim->rowCount() !== 1) {
        jsonResponse(['success' => false, 'message' => 'Could not activate this license key. It may have just been claimed by someone else.'], 409);
    }

    if ($row['valid_days'] !== null) {
        $pdo->prepare('UPDATE license_keys SET valid_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY) WHERE code = ?')
            ->execute([(int) $row['valid_days'], $code]);
    }

    $activationToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $activationToken);
    $store = $pdo->prepare('UPDATE license_keys SET activation_token_hash = ? WHERE code = ?');
    $store->execute([$tokenHash, $code]);

    $final = $pdo->prepare('SELECT valid_until FROM license_keys WHERE code = ?');
    $final->execute([$code]);

    jsonResponse(['success' => true, 'activation_token' => $activationToken, 'valid_until' => $final->fetchColumn()]);
}

/**
 * Called once at activation to cache locally, then again only whenever
 * the app happens to have internet - a background health check, never
 * something checkout blocks on. "valid: false" means revoked OR past
 * valid_until; anything else (unreachable, timeout) the app already
 * treats as "couldn't check, keep running" on the caller's side, not
 * this endpoint's concern. valid_until is echoed back so the app can
 * refresh its own cached copy - the actual offline enforcement of it
 * happens client-side using the device's own clock, not by requiring
 * this endpoint to be reachable (see LicenseService.hasCachedLicense).
 */
if ($action === 'verify' && $method === 'POST') {
    $token = trim(headerValue('Authorization'));
    if (stripos($token, 'Bearer ') === 0) {
        $token = trim(substr($token, 7));
    }
    if ($token === '') {
        jsonResponse(['success' => false, 'valid' => false, 'message' => 'Missing activation token.'], 401);
    }

    $stmt = $pdo->prepare('SELECT revoked, valid_until FROM license_keys WHERE activation_token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonResponse(['success' => true, 'valid' => false]);
    }

    // Same ' UTC' timezone gotcha as activate()'s expiry check above.
    $expired = $row['valid_until'] !== null && strtotime((string) $row['valid_until'] . ' UTC') < time();
    $valid = (int) $row['revoked'] === 0 && !$expired;
    jsonResponse(['success' => true, 'valid' => $valid, 'valid_until' => $row['valid_until']]);
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
