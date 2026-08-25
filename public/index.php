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
 * Inline-styled on purpose - email clients (Outlook desktop especially)
 * ignore <style> blocks and external stylesheets, so anything not
 * inlined simply won't render. Numbered steps use a <table> rather than
 * flexbox/grid, which old Outlook's Word rendering engine doesn't
 * support - tables are the one layout primitive every email client
 * still renders correctly. Palette matches nexapos-site/index.html's
 * CSS variables exactly (--accent #4f46e5, --accent-dark #3730a3,
 * --accent-soft #eef2ff, --bg #f7f7fb, --ink #14141f, --ink-soft
 * #55556b) so the email reads as the same product as the site, not a
 * different one - user asked for this explicitly after the first
 * version leaned mostly gray/white with just one blue button.
 * htmlspecialchars on $name since it's user-submitted and this renders
 * as real HTML, unlike the plain-text fallback right next to every
 * call site.
 */
function welcomeEmailHtml(string $name): string
{
    $safeName = htmlspecialchars($name, ENT_QUOTES);
    $steps = [
        'Download the app for your device',
        "Install it and open it - you'll land on an activation screen",
        'Message us to arrange payment',
        "We'll send your license key - enter it once, no internet needed after that",
    ];
    $stepsHtml = '';
    foreach ($steps as $i => $step) {
        $n = $i + 1;
        $stepsHtml .= <<<HTML
        <tr>
          <td style="width: 26px; vertical-align: top; padding-bottom: 12px;">
            <div style="width: 20px; height: 20px; background: #4f46e5; border-radius: 50%; color: #ffffff; font-size: 12px; font-weight: 700; line-height: 20px; text-align: center;">{$n}</div>
          </td>
          <td style="padding-left: 10px; padding-bottom: 12px; color: #14141f; font-size: 14px;">{$step}</td>
        </tr>
        HTML;
    }

    return <<<HTML
    <div style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; max-width: 480px; margin: 0 auto; background: #f7f7fb;">
      <div style="background: #4f46e5; padding: 28px 20px; text-align: center; border-radius: 12px 12px 0 0;">
        <div style="width: 44px; height: 44px; background: #ffffff; border-radius: 12px; display: inline-block; color: #4f46e5; font-size: 20px; font-weight: 700; line-height: 44px; text-align: center; margin-bottom: 10px;">N</div>
        <div style="color: #ffffff; font-size: 17px; font-weight: 700;">NexaPOS</div>
      </div>
      <div style="background: #ffffff; padding: 32px 24px; border-radius: 0 0 12px 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
        <h1 style="font-size: 22px; text-align: center; margin: 0 0 8px; color: #14141f;">Welcome, {$safeName}!</h1>
        <p style="text-align: center; color: #55556b; font-size: 14px; margin: 0 0 28px;">Offline-first point of sale for your business.</p>

        <div style="background: #eef2ff; border: 1px solid #e6e6f0; border-radius: 10px; padding: 20px; margin-bottom: 24px;">
          <p style="margin: 0 0 14px; font-weight: 600; font-size: 14px; color: #3730a3;">Next steps</p>
          <table role="presentation" style="width: 100%; border-collapse: collapse;">
            {$stepsHtml}
          </table>
        </div>

        <div style="text-align: center; margin-bottom: 12px;">
          <a href="https://keswift254.github.io/nexapos-site/#get-started" style="display: inline-block; background: #4f46e5; color: #ffffff; text-decoration: none; padding: 13px 28px; border-radius: 8px; font-size: 14px; font-weight: 600;">Download NexaPOS</a>
        </div>
        <div style="text-align: center;">
          <a href="https://wa.me/message/M5SGWZ664XJ4C1" style="display: inline-block; background: #25D366; color: #ffffff; text-decoration: none; padding: 13px 28px; border-radius: 8px; font-size: 14px; font-weight: 600;">Chat on WhatsApp</a>
        </div>

        <p style="text-align: center; color: #999999; font-size: 12px; margin: 28px 0 0;">Questions? Just reply to this email or message us on WhatsApp.</p>
      </div>
      <p style="text-align: center; color: #55556b; font-size: 12px; margin: 16px 0 0;">&copy; 2026 NexaPOS</p>
    </div>
    HTML;
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
    // db_host is safe to expose (a hostname isn't a secret) and is the
    // fastest way to confirm which database is actually live after a
    // manual switch to/from the standby - see the standby runbook. Not
    // the full DSN/credentials, just enough to tell primary from
    // standby apart at a glance.
    $dbConfig = require __DIR__ . '/../config/config.php';
    jsonResponse(['success' => true, 'service' => 'nexapos_license', 'db_host' => $dbConfig['db']['host']]);
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
                '- The NexaPOS team',
            welcomeEmailHtml($name)
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
    // license_keys.id is already a real auto-increment sequence - reused
    // directly as the printed/PDF'd key number rather than adding a
    // second counter, so "key #14" always means the 14th row, no
    // separate thing to keep in sync.
    $sequenceNumber = (int) $pdo->lastInsertId();

    $issued = $pdo->prepare('SELECT expires_at FROM license_keys WHERE code = ?');
    $issued->execute([$code]);

    jsonResponse([
        'success' => true,
        'code' => $code,
        'expires_at' => $issued->fetchColumn(),
        'valid_days' => $validDays,
        'sequence_number' => $sequenceNumber,
    ]);
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

/**
 * Public - the app checks this (piggybacking on its existing periodic
 * sync timer, see nexapos_mobile's UpdateService) to see if a newer
 * build exists. No auth: version metadata isn't sensitive, and every
 * installed copy of the app needs to be able to ask this.
 */
if ($action === 'latest_version' && $method === 'GET') {
    $stmt = $pdo->query('SELECT version, windows_url, android_url, windows_sha256, android_sha256, release_notes, updated_at FROM app_version WHERE id = 1');
    $row = $stmt->fetch();
    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'No version has been published yet.'], 404);
    }
    jsonResponse(['success' => true] + $row);
}

/**
 * Admin action - run once per real release, from generator.html's
 * "Publish app update" section, right after the new build's
 * NexaPOS.apk/NexaPOS-Windows.zip are pushed to the download site.
 */
if ($action === 'set_latest_version' && $method === 'POST') {
    requireAdmin($licenseConfig);
    $body = requestBody();
    $version = trim((string) ($body['version'] ?? ''));
    $windowsUrl = trim((string) ($body['windows_url'] ?? ''));
    $androidUrl = trim((string) ($body['android_url'] ?? ''));
    $releaseNotes = trim((string) ($body['release_notes'] ?? ''));
    $windowsSha256 = strtolower(trim((string) ($body['windows_sha256'] ?? '')));
    $androidSha256 = strtolower(trim((string) ($body['android_sha256'] ?? '')));
    if ($version === '' || $windowsUrl === '' || $androidUrl === '') {
        jsonResponse(['success' => false, 'message' => 'version, windows_url, and android_url are required.'], 422);
    }
    // Optional, but if given must actually be a SHA-256 (64 hex chars) -
    // catches a truncated paste or the wrong value pasted in, rather
    // than silently storing something that could never match a real
    // file's hash and would brick every install for this version.
    if ($windowsSha256 !== '' && !preg_match('/^[0-9a-f]{64}$/', $windowsSha256)) {
        jsonResponse(['success' => false, 'message' => 'windows_sha256 must be a 64-character hex SHA-256, or left blank.'], 422);
    }
    if ($androidSha256 !== '' && !preg_match('/^[0-9a-f]{64}$/', $androidSha256)) {
        jsonResponse(['success' => false, 'message' => 'android_sha256 must be a 64-character hex SHA-256, or left blank.'], 422);
    }
    $upsert = $pdo->prepare('
        INSERT INTO app_version (id, version, windows_url, android_url, windows_sha256, android_sha256, release_notes)
        VALUES (1, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE version = VALUES(version), windows_url = VALUES(windows_url),
            android_url = VALUES(android_url), windows_sha256 = VALUES(windows_sha256),
            android_sha256 = VALUES(android_sha256), release_notes = VALUES(release_notes)
    ');
    $upsert->execute([
        $version,
        $windowsUrl,
        $androidUrl,
        $windowsSha256 !== '' ? $windowsSha256 : null,
        $androidSha256 !== '' ? $androidSha256 : null,
        $releaseNotes !== '' ? $releaseNotes : null,
    ]);
    jsonResponse(['success' => true]);
}

jsonResponse(['success' => false, 'message' => 'Unknown action.'], 404);
