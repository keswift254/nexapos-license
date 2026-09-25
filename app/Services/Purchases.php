<?php

declare(strict_types=1);

namespace License\Services;

use License\Core\Database;
use License\Core\LicenseCode;
use PDO;

/**
 * Selling a license from the app's activation screen.
 *
 * The flow (every step decided HERE, never by the app):
 *  1. start()   the app names a device, a plan id and an email. The plan's price
 *               and length come from config, a row is stored, and Paystack is
 *               asked for a checkout page; the app is handed only its URL.
 *  2. The customer pays on Paystack's page (card, M-Pesa, ...).
 *  3. status()  the app polls. Once Paystack itself says the payment succeeded
 *               for EXACTLY the stored amount in KES, the row becomes `paid` and,
 *               because the paying device is the one asking, the license is
 *               created, bound to that device and returned - once; asking again
 *               returns the same license.
 *  webhook()    optional and additive: a signed call from Paystack marks the row
 *               `paid` even if the app is closed. It never issues anything: the
 *               license period starts when the customer actually receives it.
 *
 * Money safety: the amount and currency are compared with what THIS server
 * stored, never with anything the app sent; a mismatch is refused. Issuing is
 * inside a row-locked transaction, so two polls (or a poll and a retry) racing
 * can never create two licenses for one payment.
 */
final class Purchases
{
    /** A repeat tap within this window gets the same checkout page back, not a second charge attempt. */
    private const PENDING_REUSE_MINUTES = 30;
    private const STARTS_PER_DEVICE_PER_HOUR = 6;
    private const STARTS_PER_IP_PER_HOUR = 12;

    public function __construct(
        private PDO $pdo,
        private array $config,
        private Paystack $paystack,
    ) {
    }

    /** @return array{0: array, 1: int} */
    public function plans(): array
    {
        return [[
            'success' => true,
            'purchasing_enabled' => $this->paystack->enabled(),
            'currency' => 'KES',
            'plans' => array_values(array_map(
                static fn (array $plan): array => [
                    'id' => $plan['id'],
                    'label' => $plan['label'],
                    'months' => $plan['months'],
                    'amount_kes' => $plan['amount_kes'],
                ],
                $this->planList()
            )),
        ], 200];
    }

    /** @return array{0: array, 1: int} */
    public function start(array $body, string $ip): array
    {
        $deviceId = trim((string) ($body['device_id'] ?? ''));
        $planId = trim((string) ($body['plan_id'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if (preg_match('/^[\x21-\x7E]{1,64}$/', $deviceId) !== 1) {
            return [['success' => false, 'message' => 'A valid device_id is required.'], 422];
        }
        $plan = $this->plan($planId);
        if ($plan === null) {
            return [['success' => false, 'message' => 'Choose one of the listed plans.'], 422];
        }
        if ($email === '' || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [['success' => false, 'message' => 'Enter a valid email address - Paystack sends your receipt there.'], 422];
        }
        if (!$this->paystack->enabled()) {
            return [['success' => false, 'message' => 'Online payment is not available yet. Contact NexaPOS for a license key.'], 503];
        }

        Database::ensurePurchaseTable();

        // Nobody has any business starting dozens of checkouts: each one is a real
        // call to Paystack under the vendor's key.
        $perDevice = $this->pdo->prepare('SELECT COUNT(*) FROM license_purchases WHERE device_id = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
        $perDevice->execute([$deviceId]);
        if ((int) $perDevice->fetchColumn() >= self::STARTS_PER_DEVICE_PER_HOUR) {
            return [['success' => false, 'message' => 'Too many payment attempts from this device. Try again in a little while.'], 429];
        }
        if ($ip !== '') {
            $perIp = $this->pdo->prepare('SELECT COUNT(*) FROM license_purchases WHERE ip_address = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
            $perIp->execute([$ip]);
            if ((int) $perIp->fetchColumn() >= self::STARTS_PER_IP_PER_HOUR) {
                return [['success' => false, 'message' => 'Too many payment attempts from this connection. Try again later.'], 429];
            }
        }

        // A second tap on the same plan while the first checkout is still open
        // gets that same checkout page back.
        $open = $this->pdo->prepare(
            "SELECT reference, authorization_url FROM license_purchases
             WHERE device_id = ? AND plan_id = ? AND email = ? AND status = 'pending' AND authorization_url IS NOT NULL
               AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . self::PENDING_REUSE_MINUTES . " MINUTE)
             ORDER BY id DESC LIMIT 1"
        );
        $open->execute([$deviceId, $planId, $email]);
        $existing = $open->fetch();
        if ($existing) {
            return [$this->startedPayload((string) $existing['reference'], (string) $existing['authorization_url'], $plan), 200];
        }

        $reference = 'nxl-' . bin2hex(random_bytes(10));
        $amountMinor = $plan['amount_kes'] * 100; // KES has 100 cents; Paystack works in the smallest unit
        $insert = $this->pdo->prepare(
            'INSERT INTO license_purchases (reference, device_id, plan_id, months, amount_minor, currency, email, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $insert->execute([$reference, $deviceId, $planId, $plan['months'], $amountMinor, 'KES', $email, $ip !== '' ? $ip : null]);
        $id = (int) $this->pdo->lastInsertId();

        try {
            $answer = $this->paystack->initialize([
                'email' => $email,
                'amount' => $amountMinor,
                'currency' => 'KES',
                'reference' => $reference,
                'callback_url' => rtrim((string) ($this->config['public_base_url'] ?? ''), '?&') . '?action=payment_done',
                'metadata' => [
                    'product' => 'NexaPOS license',
                    'plan_id' => $planId,
                    'device_id' => $deviceId,
                    'custom_fields' => [
                        ['display_name' => 'Plan', 'variable_name' => 'plan', 'value' => $plan['label']],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('[nexapos_license] Paystack initialize failed: ' . $e->getMessage());
            $this->markFailed($id, 'init_failed');
            return [['success' => false, 'message' => 'Could not start the payment. Check your internet connection and try again.'], 502];
        }

        $url = (string) (($answer['data']['authorization_url'] ?? ''));
        if (($answer['status'] ?? false) !== true || $url === '') {
            error_log('[nexapos_license] Paystack refused to start a payment: ' . (string) ($answer['message'] ?? 'no message'));
            $this->markFailed($id, 'init_refused');
            return [['success' => false, 'message' => 'The payment could not be started. Try again, or contact NexaPOS.'], 502];
        }

        $this->pdo->prepare('UPDATE license_purchases SET authorization_url = ? WHERE id = ?')->execute([substr($url, 0, 500), $id]);
        return [$this->startedPayload($reference, $url, $plan), 200];
    }

    /** @return array{0: array, 1: int} */
    public function status(array $body): array
    {
        $reference = trim((string) ($body['reference'] ?? ''));
        $deviceId = trim((string) ($body['device_id'] ?? ''));
        if ($reference === '' || strlen($reference) > 64 || $deviceId === '') {
            return [['success' => false, 'message' => 'reference and device_id are required.'], 422];
        }
        Database::ensurePurchaseTable();

        $find = $this->pdo->prepare('SELECT * FROM license_purchases WHERE reference = ? AND device_id = ?');
        $find->execute([$reference, $deviceId]);
        $purchase = $find->fetch();
        // The same answer for "no such payment" and "someone else's payment": a
        // reference is not something to be able to probe.
        if (!$purchase) {
            return [['success' => false, 'message' => 'Unknown payment.'], 404];
        }

        if ($purchase['status'] === 'issued') {
            return [$this->issuedPayload((string) $purchase['license_code']), 200];
        }
        if ($purchase['status'] === 'failed') {
            return [$this->failedPayload($purchase), 200];
        }

        if ($purchase['status'] === 'pending') {
            $recent = $purchase['last_checked_at'] !== null
                && (time() - (int) strtotime($purchase['last_checked_at'] . ' UTC')) < (int) ($this->config['purchase_verify_every_seconds'] ?? 2);
            if ($recent) {
                return [['success' => true, 'status' => 'pending'], 200];
            }
            $this->pdo->prepare('UPDATE license_purchases SET last_checked_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$purchase['id']]);
            try {
                $verified = $this->paystack->verify($reference);
            } catch (\Throwable $e) {
                // Paystack having a bad moment is not the customer's problem, and
                // not a reason to call the payment failed: ask again next time.
                error_log('[nexapos_license] Paystack verify failed: ' . $e->getMessage());
                return [['success' => true, 'status' => 'pending'], 200];
            }
            $verdict = $this->judge($verified, $purchase);
            if ($verdict === 'failed' || $verdict === 'mismatch') {
                $this->markFailed((int) $purchase['id'], $verdict === 'mismatch' ? 'amount_mismatch' : (string) ($verified['data']['status'] ?? 'failed'));
                $purchase['status'] = 'failed';
                $purchase['paystack_status'] = $verdict === 'mismatch' ? 'amount_mismatch' : 'failed';
                return [$this->failedPayload($purchase), 200];
            }
            if ($verdict === 'pending') {
                return [['success' => true, 'status' => 'pending'], 200];
            }
            $this->markPaid($purchase);
        }

        // Paid (just now, by the webhook earlier, or by an earlier poll that
        // then could not finish issuing): hand over the license.
        try {
            $code = $this->issue((int) $purchase['id']);
        } catch (\Throwable $e) {
            error_log('[nexapos_license] Could not issue a paid license (' . $reference . '): ' . $e->getMessage());
            return [['success' => false, 'message' => 'Your payment went through, but the license could not be created yet. Try again in a moment - you will not be charged twice.'], 500];
        }
        return [$this->issuedPayload($code), 200];
    }

    /**
     * Paystack telling us about a payment. Only ever moves a `pending` row to
     * `paid`; issuing is left to the paying device (see the class comment).
     *
     * @return array{0: array, 1: int}
     */
    public function webhook(string $rawBody, string $signature): array
    {
        if (!$this->paystack->validSignature($rawBody, $signature)) {
            return [['success' => false, 'message' => 'Bad signature.'], 401];
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event) || ($event['event'] ?? '') !== 'charge.success' || !is_array($event['data'] ?? null)) {
            return [['success' => true], 200]; // some other event: nothing to do, and no reason for Paystack to retry
        }
        $reference = (string) ($event['data']['reference'] ?? '');
        if ($reference === '') {
            return [['success' => true], 200];
        }
        Database::ensurePurchaseTable();
        $find = $this->pdo->prepare('SELECT * FROM license_purchases WHERE reference = ?');
        $find->execute([$reference]);
        $purchase = $find->fetch();
        if ($purchase && $purchase['status'] === 'pending') {
            $verdict = $this->judge(['status' => true, 'data' => $event['data']], $purchase);
            if ($verdict === 'paid') {
                $this->markPaid($purchase);
            } elseif ($verdict === 'mismatch') {
                $this->markFailed((int) $purchase['id'], 'amount_mismatch');
            }
        }
        return [['success' => true], 200];
    }

    /**
     * Hands over every payment made with [email] that was paid for but never
     * collected (the customer paid, then lost or reinstalled the app before it
     * asked for its license), to [deviceId]. Only ever called once the customer
     * has proved they own the email (see Recovery). Returns how many licenses
     * were issued.
     */
    public function claimPaidFor(string $email, string $deviceId): int
    {
        Database::ensurePurchaseTable();
        $find = $this->pdo->prepare("SELECT id FROM license_purchases WHERE email = ? AND status = 'paid' ORDER BY id ASC");
        $find->execute([$email]);
        $claimed = 0;
        foreach ($find->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->pdo->prepare("UPDATE license_purchases SET device_id = ? WHERE id = ? AND status = 'paid'")->execute([$deviceId, $id]);
            try {
                $this->issue((int) $id);
                $claimed++;
            } catch (\Throwable $e) {
                error_log('[nexapos_license] Could not issue a claimed purchase (' . $id . '): ' . $e->getMessage());
            }
        }
        return $claimed;
    }

    /** For the vendor (admin): what has been bought, most recent first - to reconcile paid-but-never-claimed payments. */
    public function recent(): array
    {
        Database::ensurePurchaseTable();
        $rows = $this->pdo->query(
            'SELECT reference, device_id, plan_id, months, amount_minor, currency, email, status, paystack_status, license_code, created_at, paid_at, issued_at
             FROM license_purchases ORDER BY id DESC LIMIT 100'
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['amount_kes'] = intdiv((int) $row['amount_minor'], 100);
            unset($row['amount_minor']);
        }
        return $rows;
    }

    // ---------------------------------------------------------------- internals

    /** @return list<array{id: string, label: string, months: int, amount_kes: int}> */
    private function planList(): array
    {
        $plans = [];
        foreach ((array) ($this->config['plans'] ?? []) as $plan) {
            if (!is_array($plan)) {
                continue;
            }
            $id = (string) ($plan['id'] ?? '');
            $months = (int) ($plan['months'] ?? 0);
            $amount = (int) ($plan['amount_kes'] ?? 0);
            if ($id === '' || $months < 1 || $amount < 1) {
                continue; // a malformed plan is left out rather than sold wrongly
            }
            $plans[] = ['id' => $id, 'label' => (string) ($plan['label'] ?? $id), 'months' => $months, 'amount_kes' => $amount];
        }
        return $plans;
    }

    private function plan(string $id): ?array
    {
        foreach ($this->planList() as $plan) {
            if ($plan['id'] === $id) {
                return $plan;
            }
        }
        return null;
    }

    private function startedPayload(string $reference, string $url, array $plan): array
    {
        return [
            'success' => true,
            'reference' => $reference,
            'authorization_url' => $url,
            'plan' => ['id' => $plan['id'], 'label' => $plan['label'], 'months' => $plan['months'], 'amount_kes' => $plan['amount_kes']],
        ];
    }

    private function issuedPayload(string $code): array
    {
        return ['success' => true, 'status' => 'issued', 'code' => $code];
    }

    private function failedPayload(array $purchase): array
    {
        $why = (string) ($purchase['paystack_status'] ?? '');
        $message = $why === 'amount_mismatch'
            ? 'The payment did not match the price of this plan, so nothing was issued. Contact NexaPOS with reference ' . $purchase['reference'] . '.'
            : 'The payment did not go through. You have not been charged for a license - try again.';
        return ['success' => true, 'status' => 'failed', 'message' => $message];
    }

    /**
     * What Paystack's answer means for this purchase.
     *   paid     - the payment succeeded, for exactly the stored amount in KES
     *   mismatch - it succeeded but for a different amount/currency/reference
     *   failed   - it failed or was reversed
     *   pending  - anything else (not paid yet, still processing, unknown to Paystack)
     */
    private function judge(array $answer, array $purchase): string
    {
        $data = $answer['data'] ?? null;
        if (($answer['status'] ?? false) !== true || !is_array($data)) {
            return 'pending';
        }
        $status = strtolower((string) ($data['status'] ?? ''));
        if ($status === 'failed' || $status === 'reversed') {
            return 'failed';
        }
        if ($status !== 'success') {
            return 'pending';
        }
        $matches = (int) ($data['amount'] ?? -1) === (int) $purchase['amount_minor']
            && strtoupper((string) ($data['currency'] ?? '')) === (string) $purchase['currency']
            && (string) ($data['reference'] ?? '') === (string) $purchase['reference'];
        return $matches ? 'paid' : 'mismatch';
    }

    private function markFailed(int $id, string $paystackStatus): void
    {
        $this->pdo->prepare("UPDATE license_purchases SET status = 'failed', paystack_status = ? WHERE id = ? AND status = 'pending'")
            ->execute([substr($paystackStatus, 0, 40), $id]);
    }

    /** pending -> paid, atomically; the vendor hears about it only on the call that actually made the change. */
    private function markPaid(array $purchase): void
    {
        $update = $this->pdo->prepare("UPDATE license_purchases SET status = 'paid', paystack_status = 'success', paid_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending'");
        $update->execute([$purchase['id']]);
        if ($update->rowCount() !== 1) {
            return;
        }
        try {
            $mailer = new Mailer($this->config);
            $mailer->send(
                (string) ($this->config['notify_email'] ?? ''),
                'NexaPOS payment received: KSh ' . intdiv((int) $purchase['amount_minor'], 100),
                "A license was paid for.\n\nPlan: " . $purchase['plan_id'] . ' (' . $purchase['months'] . " months)\nAmount: KSh " .
                    intdiv((int) $purchase['amount_minor'], 100) . "\nCustomer email: " . $purchase['email'] .
                    "\nReference: " . $purchase['reference'] . "\nDevice: " . $purchase['device_id'] . "\n\nThe license is issued to the device automatically."
            );
        } catch (\Throwable $e) {
            error_log('[nexapos_license] Could not send the payment notification: ' . $e->getMessage());
        }
    }

    /**
     * Creates the license for a paid purchase, bound to the device that paid, and
     * returns its code. Idempotent: a purchase already issued returns its code.
     * Row-locked, so two callers racing cannot create two licenses.
     */
    private function issue(int $purchaseId): string
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM license_purchases WHERE id = ? FOR UPDATE');
            $lock->execute([$purchaseId]);
            $purchase = $lock->fetch();
            if (!$purchase) {
                throw new \RuntimeException('Purchase vanished.');
            }
            if ($purchase['status'] === 'issued') {
                $this->pdo->commit();
                return (string) $purchase['license_code'];
            }
            if ($purchase['status'] !== 'paid') {
                throw new \RuntimeException('Purchase is not paid.');
            }

            // Renewing early must lose nothing: the new period starts when the
            // device's current one ends (or now, if it has none running).
            $base = $this->pdo->prepare(
                'SELECT GREATEST(UTC_TIMESTAMP(), COALESCE(MAX(valid_until), UTC_TIMESTAMP()))
                 FROM license_keys WHERE device_id = ? AND revoked = 0 AND valid_until IS NOT NULL'
            );
            $base->execute([$purchase['device_id']]);
            $startsAt = (string) $base->fetchColumn();
            $months = (int) $purchase['months'];

            $code = LicenseCode::generate($this->pdo);
            // Already bound and activated: the app's next step is the ordinary
            // activate() call, which recognises the device and hands out a token.
            $this->pdo->prepare(
                'INSERT INTO license_keys (code, expires_at, activated_at, device_id, valid_days, valid_until)
                 VALUES (?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), UTC_TIMESTAMP(), ?,
                         DATEDIFF(DATE_ADD(?, INTERVAL ? MONTH), UTC_TIMESTAMP()), DATE_ADD(?, INTERVAL ? MONTH))'
            )->execute([$code, $purchase['device_id'], $startsAt, $months, $startsAt, $months]);
            $this->pdo->prepare("UPDATE license_purchases SET status = 'issued', license_code = ?, issued_at = UTC_TIMESTAMP() WHERE id = ?")
                ->execute([$code, $purchaseId]);
            $this->pdo->commit();
            return $code;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
