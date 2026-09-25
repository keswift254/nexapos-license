<?php

declare(strict_types=1);

namespace License\Services;

use License\Core\Database;
use PDO;

/**
 * Getting a license onto a new device.
 *
 * A license is bound to the device that first used it, and the app makes a new
 * device ID every time it is installed fresh (Android wipes it on uninstall).
 * So a customer who reinstalls, or changes phone, arrives at a device the server
 * has never seen while their license sits bound to the old one. Two ways out:
 *
 *  Self-service (restoreStart / restoreConfirm)
 *      The customer names the email they paid with, we email a 6-digit code to
 *      THAT address, and typing the code back proves they own it. Then the
 *      license bought with that email moves to this device. Everything is
 *      decided here: the app only ever sees its license code back.
 *
 *  Vendor (find / transfer, behind the admin secret)
 *      For customers who were sold a key by hand, or who cannot get the email:
 *      look the customer up, then move their license to the device ID they read
 *      off their activation screen.
 *
 * Moving KEEPS end dates and clears the tokens the old device held, so the old
 * device stops being licensed the next time it checks in. Every license bound to
 * the old device moves together (a customer who renewed early has a stack of
 * them; leaving the older ones behind would leave the old device licensed). It
 * does not move any shop data - that lives with the shop, not the license.
 */
final class Recovery
{
    private const CODE_MINUTES = 15;
    private const MAX_ATTEMPTS = 5;
    private const CODES_PER_EMAIL_PER_HOUR = 3;
    private const CODES_PER_DEVICE_PER_HOUR = 5;
    private const CODES_PER_IP_PER_HOUR = 10;
    /** How often a customer can move the same license to a new device by themselves. */
    private const SELF_MOVES_PER_WINDOW = 3;
    private const SELF_MOVE_WINDOW_DAYS = 90;

    public function __construct(
        private PDO $pdo,
        private array $config,
        private Purchases $purchases,
    ) {
    }

    // ------------------------------------------------------------ the customer

    /** @return array{0: array, 1: int} */
    public function restoreStart(array $body, string $ip): array
    {
        $deviceId = trim((string) ($body['device_id'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if (!$this->validDeviceId($deviceId)) {
            return [['success' => false, 'message' => 'A valid device_id is required.'], 422];
        }
        if ($email === '' || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [['success' => false, 'message' => 'Enter the email address you paid with.'], 422];
        }
        Database::ensurePurchaseTable();
        Database::ensureRecoveryTables();

        if (
            $this->countSince('email', $email) >= self::CODES_PER_EMAIL_PER_HOUR
            || $this->countSince('device_id', $deviceId) >= self::CODES_PER_DEVICE_PER_HOUR
            || ($ip !== '' && $this->countSince('ip_address', $ip) >= self::CODES_PER_IP_PER_HOUR)
        ) {
            return [['success' => false, 'message' => 'Too many attempts. Wait a little while, then try again.'], 429];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->pdo->prepare(
            'INSERT INTO license_restore_codes (email, device_id, code_hash, ip_address, created_at, expires_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . self::CODE_MINUTES . ' MINUTE))'
        )->execute([$email, $deviceId, $this->hashCode($email, $code), $ip !== '' ? $ip : null]);

        // The same answer whether or not this email ever bought anything: the
        // form must not be a way to find out who the customers are. The mail
        // itself only goes where there is something to restore.
        if ($this->hasPurchase($email)) {
            try {
                (new Mailer($this->config))->send(
                    $email,
                    'Your NexaPOS restore code: ' . $code,
                    "Your NexaPOS restore code is: $code\n\nIt is valid for " . self::CODE_MINUTES .
                        " minutes. Type it into NexaPOS to move your license to this device.\n\n" .
                        "If you did not ask for this, ignore this email - nothing changes unless the code is entered.",
                    self::codeEmailHtml($code)
                );
            } catch (\Throwable $e) {
                error_log('[nexapos_license] Could not send a restore code: ' . $e->getMessage());
                return [['success' => false, 'message' => 'The code could not be emailed just now. Try again in a minute, or contact NexaPOS.'], 502];
            }
        }
        return [[
            'success' => true,
            'message' => 'If a NexaPOS purchase was made with this email, a 6-digit code is on its way. Check your spam folder too.',
        ], 200];
    }

    /** @return array{0: array, 1: int} */
    public function restoreConfirm(array $body, string $ip): array
    {
        $deviceId = trim((string) ($body['device_id'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $entered = preg_replace('/\D/', '', (string) ($body['code'] ?? '')) ?? '';
        if (!$this->validDeviceId($deviceId) || $email === '' || strlen($entered) !== 6) {
            return [['success' => false, 'message' => 'Enter the 6-digit code from the email.'], 422];
        }
        Database::ensurePurchaseTable();
        Database::ensureRecoveryTables();

        // Only the newest code for this email on this device counts, so asking
        // again cancels the earlier one.
        $find = $this->pdo->prepare(
            'SELECT * FROM license_restore_codes
             WHERE email = ? AND device_id = ? AND used = 0 AND expires_at > UTC_TIMESTAMP()
             ORDER BY id DESC LIMIT 1'
        );
        $find->execute([$email, $deviceId]);
        $row = $find->fetch();
        $wrong = [['success' => false, 'message' => 'That code is not right, or it has expired. Ask for a new one.'], 422];
        if (!$row) {
            return $wrong;
        }
        // Count the attempt BEFORE looking at it, atomically, so nobody can try
        // codes faster than the limit by racing requests.
        $spend = $this->pdo->prepare('UPDATE license_restore_codes SET attempts = attempts + 1 WHERE id = ? AND used = 0 AND attempts < ' . self::MAX_ATTEMPTS);
        $spend->execute([$row['id']]);
        if ($spend->rowCount() !== 1) {
            return [['success' => false, 'message' => 'Too many wrong codes. Ask for a new one.'], 429];
        }
        if (!hash_equals((string) $row['code_hash'], $this->hashCode($email, $entered))) {
            return $wrong;
        }
        // One use only, even if two requests carry the right code at once.
        $use = $this->pdo->prepare('UPDATE license_restore_codes SET used = 1 WHERE id = ? AND used = 0');
        $use->execute([$row['id']]);
        if ($use->rowCount() !== 1) {
            return $wrong;
        }

        // The email is proven. First bring over the license already issued to it...
        $license = $this->currentLicenseFor($email);
        $moved = [];
        if ($license !== null && (string) $license['device_id'] !== $deviceId) {
            $recent = $this->pdo->prepare(
                "SELECT COUNT(*) FROM license_transfers WHERE code = ? AND moved_by = 'self'
                 AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . self::SELF_MOVE_WINDOW_DAYS . ' DAY)'
            );
            $recent->execute([$license['code']]);
            if ((int) $recent->fetchColumn() >= self::SELF_MOVES_PER_WINDOW) {
                return [['success' => false, 'message' => 'This license has already been moved several times recently. Contact NexaPOS with your device ID and we will sort it out.'], 429];
            }
            $moved = $this->moveDevice((string) $license['device_id'], $deviceId, 'self', $ip);
        }
        // ... then anything paid for with this email that never reached a device.
        // In this order so that such a purchase stacks on top of what is moved.
        $claimed = $this->purchases->claimPaidFor($email, $deviceId);

        $current = $this->currentLicenseForDevice($deviceId);
        if ($current === null) {
            return [['success' => false, 'message' => 'No active license was found for this email. If it has expired, choose a plan to renew.'], 404];
        }
        return [[
            'success' => true,
            'code' => $current['code'],
            'moved' => count($moved),
            'claimed' => $claimed,
            'message' => 'Your license is on this device.',
        ], 200];
    }

    // -------------------------------------------------------------- the vendor

    /**
     * Everything known about a customer: by email, by device ID, or by license code.
     *
     * @return array{0: array, 1: int}
     */
    public function find(string $query): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 190) {
            return [['success' => false, 'message' => 'Enter an email, a device ID or a license code.'], 422];
        }
        Database::ensurePurchaseTable();
        Database::ensureRecoveryTables();

        $codes = [];
        $purchases = [];
        $lead = null;
        if (str_contains($query, '@')) {
            $needle = strtolower($query);
            $stmt = $this->pdo->prepare('SELECT * FROM license_purchases WHERE email = ? ORDER BY id DESC LIMIT 50');
            $stmt->execute([$needle]);
            $purchases = $stmt->fetchAll();
            foreach ($purchases as $p) {
                if ($p['license_code'] !== null) {
                    $codes[$p['license_code']] = true;
                }
            }
            $leadStmt = $this->pdo->prepare('SELECT name, email, business_name, phone, created_at FROM leads WHERE email = ?');
            $leadStmt->execute([$needle]);
            $lead = $leadStmt->fetch() ?: null;
        } else {
            $stmt = $this->pdo->prepare('SELECT code FROM license_keys WHERE code = ? OR device_id = ?');
            $stmt->execute([strtoupper($query), $query]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
                $codes[$code] = true;
            }
            $stmt = $this->pdo->prepare('SELECT * FROM license_purchases WHERE device_id = ? OR license_code = ? ORDER BY id DESC LIMIT 50');
            $stmt->execute([$query, strtoupper($query)]);
            $purchases = $stmt->fetchAll();
            foreach ($purchases as $p) {
                if ($p['license_code'] !== null) {
                    $codes[$p['license_code']] = true;
                }
            }
        }

        $licenses = [];
        $transfers = [];
        foreach (array_keys($codes) as $code) {
            $stmt = $this->pdo->prepare('SELECT code, device_id, valid_days, valid_until, activated_at, expires_at, revoked, (activation_token_hash IS NOT NULL) AS has_token FROM license_keys WHERE code = ?');
            $stmt->execute([$code]);
            if ($row = $stmt->fetch()) {
                $row['revoked'] = (int) $row['revoked'] === 1;
                $row['has_token'] = (int) $row['has_token'] === 1;
                $licenses[] = $row;
            }
            $moves = $this->pdo->prepare('SELECT code, from_device_id, to_device_id, moved_by, created_at FROM license_transfers WHERE code = ? ORDER BY id DESC LIMIT 10');
            $moves->execute([$code]);
            foreach ($moves->fetchAll() as $move) {
                $transfers[] = $move;
            }
        }
        foreach ($purchases as &$p) {
            $p['amount_kes'] = intdiv((int) $p['amount_minor'], 100);
            unset($p['amount_minor'], $p['authorization_url'], $p['last_checked_at'], $p['ip_address'], $p['id']);
        }
        unset($p);

        return [[
            'success' => true,
            'licenses' => $licenses,
            'purchases' => $purchases,
            'transfers' => $transfers,
            'lead' => $lead,
        ], 200];
    }

    /**
     * Moves a license to the device ID the customer reads off their activation
     * screen. The license is named by its code, or by the device it is on now.
     *
     * @return array{0: array, 1: int}
     */
    public function transfer(array $body, string $ip): array
    {
        $code = strtoupper(trim((string) ($body['code'] ?? '')));
        $fromDevice = trim((string) ($body['from_device_id'] ?? ''));
        $toDevice = trim((string) ($body['new_device_id'] ?? ''));
        if (!$this->validDeviceId($toDevice)) {
            return [['success' => false, 'message' => 'Enter the customer\'s new device ID (shown on their activation screen).'], 422];
        }
        if ($code === '' && $fromDevice === '') {
            return [['success' => false, 'message' => 'Give either the license code or the device it is on now.'], 422];
        }
        Database::ensureRecoveryTables();

        if ($code !== '') {
            $stmt = $this->pdo->prepare('SELECT * FROM license_keys WHERE code = ?');
            $stmt->execute([$code]);
        } else {
            // The device's current license: the one that runs longest.
            $stmt = $this->pdo->prepare('SELECT * FROM license_keys WHERE device_id = ? AND revoked = 0 ORDER BY valid_until IS NULL DESC, valid_until DESC, id DESC LIMIT 1');
            $stmt->execute([$fromDevice]);
        }
        $license = $stmt->fetch();
        if (!$license) {
            return [['success' => false, 'message' => 'No such license.'], 404];
        }
        if ((int) $license['revoked'] === 1) {
            return [['success' => false, 'message' => 'That license was revoked, so it cannot be moved. Unrevoke it first if that was a mistake.'], 422];
        }
        if ($license['device_id'] === null) {
            return [['success' => false, 'message' => 'That license has not been used on any device yet - just give the customer the key and they can enter it on the new device.'], 422];
        }
        if (hash_equals((string) $license['device_id'], $toDevice)) {
            return [['success' => false, 'message' => 'That license is already on this device.'], 422];
        }

        $moved = $this->moveDevice((string) $license['device_id'], $toDevice, 'admin', $ip);
        if ($moved === []) {
            return [['success' => false, 'message' => 'That license just changed hands - look the customer up again.'], 409];
        }

        $expired = $license['valid_until'] !== null && strtotime((string) $license['valid_until'] . ' UTC') < time();
        return [[
            'success' => true,
            'code' => $license['code'],
            'from_device_id' => $license['device_id'],
            'to_device_id' => $toDevice,
            'moved_codes' => $moved,
            'valid_until' => $license['valid_until'],
            'expired' => $expired,
            'message' => $expired
                ? 'Moved - but this license has already expired, so extend it before the customer enters the key.'
                : 'Moved. On the new device the customer opens NexaPOS and enters this key (or taps Restore), and it activates with the same end date.',
        ], 200];
    }

    // ---------------------------------------------------------------- internals

    /**
     * Rebinds every (not revoked) license on [fromDevice] to [toDevice]: same end
     * dates, new owner, and the old device's tokens are dropped so it stops being
     * licensed when it next checks in. Each license is claimed with a WHERE that
     * names the device we saw, so a move that raced another one changes nothing.
     *
     * @return list<string> the codes that moved
     */
    private function moveDevice(string $fromDevice, string $toDevice, string $by, string $ip): array
    {
        $this->pdo->beginTransaction();
        try {
            $find = $this->pdo->prepare('SELECT code FROM license_keys WHERE device_id = ? AND revoked = 0 ORDER BY id FOR UPDATE');
            $find->execute([$fromDevice]);
            $moved = [];
            foreach ($find->fetchAll(PDO::FETCH_COLUMN) as $code) {
                $update = $this->pdo->prepare(
                    'UPDATE license_keys SET device_id = ?, activation_token_hash = NULL
                     WHERE code = ? AND device_id = ? AND revoked = 0'
                );
                $update->execute([$toDevice, $code, $fromDevice]);
                if ($update->rowCount() !== 1) {
                    continue;
                }
                $this->pdo->prepare('INSERT INTO license_transfers (code, from_device_id, to_device_id, moved_by, ip_address) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$code, $fromDevice, $toDevice, $by, $ip !== '' ? $ip : null]);
                $moved[] = (string) $code;
            }
            $this->pdo->commit();
            return $moved;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** The license bought with this email that runs longest and is still good. */
    private function currentLicenseFor(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT k.* FROM license_keys k
             JOIN license_purchases p ON p.license_code = k.code
             WHERE p.email = ? AND p.status = 'issued' AND k.revoked = 0 AND k.device_id IS NOT NULL
               AND (k.valid_until IS NULL OR k.valid_until > UTC_TIMESTAMP())
             ORDER BY k.valid_until IS NULL DESC, k.valid_until DESC, k.id DESC LIMIT 1"
        );
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    /** What this device is licensed by right now (after a move and any claim). */
    private function currentLicenseForDevice(string $deviceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM license_keys WHERE device_id = ? AND revoked = 0
               AND (valid_until IS NULL OR valid_until > UTC_TIMESTAMP())
             ORDER BY valid_until IS NULL DESC, valid_until DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$deviceId]);
        return $stmt->fetch() ?: null;
    }

    private function hasPurchase(string $email): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM license_purchases WHERE email = ? AND status IN ('issued', 'paid') LIMIT 1");
        $stmt->execute([$email]);
        return (bool) $stmt->fetchColumn();
    }

    private function countSince(string $column, string $value): int
    {
        // $column is one of three fixed names below, never caller input.
        if (!in_array($column, ['email', 'device_id', 'ip_address'], true)) {
            throw new \InvalidArgumentException('Unknown column.');
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM license_restore_codes WHERE $column = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)");
        $stmt->execute([$value]);
        return (int) $stmt->fetchColumn();
    }

    private function hashCode(string $email, string $code): string
    {
        return hash_hmac('sha256', $email . '|' . $code, 'restore:' . (string) ($this->config['admin_secret'] ?? ''));
    }

    private function validDeviceId(string $deviceId): bool
    {
        return preg_match('/^[\x21-\x7E]{1,64}$/', $deviceId) === 1;
    }

    private static function codeEmailHtml(string $code): string
    {
        $spaced = htmlspecialchars(substr($code, 0, 3) . ' ' . substr($code, 3), ENT_QUOTES);
        return <<<HTML
        <div style="font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; background: #f7f7fb; padding: 32px 16px;">
          <div style="max-width: 460px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 32px 28px; text-align: center;">
            <div style="width: 44px; height: 44px; background: #4f46e5; border-radius: 12px; color: #ffffff; font-size: 20px; font-weight: 700; line-height: 44px; margin: 0 auto 14px;">N</div>
            <h1 style="font-size: 20px; color: #14141f; margin: 0 0 8px;">Your restore code</h1>
            <p style="color: #55556b; font-size: 15px; line-height: 1.5; margin: 0 0 20px;">Type this code into NexaPOS to move your license to this device.</p>
            <div style="font-size: 34px; font-weight: 700; letter-spacing: 6px; color: #4f46e5; background: #f0efff; border-radius: 10px; padding: 14px 0; margin: 0 0 20px;">$spaced</div>
            <p style="color: #999999; font-size: 12px; line-height: 1.5; margin: 0;">It works for 15 minutes. If you did not ask for it, ignore this email - nothing changes unless the code is entered.</p>
          </div>
        </div>
        HTML;
    }
}
