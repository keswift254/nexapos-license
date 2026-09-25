<?php

declare(strict_types=1);

namespace License\Services;

use PDO;

/**
 * What can be bought from the activation screen, kept in the database so the vendor
 * can change it from generator.html (a price, how long a plan lasts, a new plan, a
 * plan removed) without a deploy.
 *
 * config/license.php holds only the STARTING set: it fills the table the first time
 * the table is created and is never consulted again, so deleting a plan really
 * removes it. Nothing here touches a purchase that already exists: a purchase
 * stores its own price and length when the checkout starts, so changing or
 * deleting a plan can never change what somebody who is mid-payment gets or pays.
 *
 * A plan lasts `months` (calendar) + `days`, or is a `lifetime` plan (a license with no
 * end date). `test` marks the temporary token-price plan used to try a real payment.
 */
final class Plans
{
    public const MAX_PLANS = 12;
    private const MAX_LABEL = 40;
    private const MAX_MONTHS = 120;
    private const MAX_DAYS = 3650;
    private const MAX_PRICE_KES = 1000000;

    private static bool $tableChecked = false;

    public function __construct(
        private PDO $pdo,
        private array $config,
    ) {
    }

    // ------------------------------------------------------------------ reading

    /** Every plan, hidden ones too - for the vendor. @return list<array> */
    public function all(): array
    {
        $this->ensureTable();
        $rows = $this->pdo->query('SELECT * FROM license_plans ORDER BY sort_order, id')->fetchAll();
        return array_map([self::class, 'normalize'], $rows);
    }

    /**
     * What customers can buy right now: active plans, and not the test plan while
     * TEST_PLAN_ENABLED says off.
     *
     * @return list<array>
     */
    public function active(): array
    {
        $testsAllowed = !empty($this->config['test_plan_enabled']);
        return array_values(array_filter(
            $this->all(),
            static fn (array $plan): bool => $plan['active'] && ($testsAllowed || !$plan['test'])
        ));
    }

    // ------------------------------------------------------------------ changing

    /** @return array{0: array, 1: int} */
    public function save(array $body): array
    {
        $this->ensureTable();
        [$plan, $problem] = self::validate($body);
        if ($plan === null) {
            return [['success' => false, 'message' => $problem], 422];
        }
        $exists = $this->pdo->prepare('SELECT 1 FROM license_plans WHERE id = ?');
        $exists->execute([$plan['id']]);
        if (!$exists->fetchColumn()) {
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM license_plans')->fetchColumn();
            if ($count >= self::MAX_PLANS) {
                return [['success' => false, 'message' => 'There is room for ' . self::MAX_PLANS . ' plans. Remove one first.'], 422];
            }
        }
        $this->pdo->prepare(
            'INSERT INTO license_plans (id, label, months, days, lifetime, amount_kes, is_test, active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE label = VALUES(label), months = VALUES(months), days = VALUES(days),
                 lifetime = VALUES(lifetime), amount_kes = VALUES(amount_kes), is_test = VALUES(is_test),
                 active = VALUES(active), sort_order = VALUES(sort_order)'
        )->execute([
            $plan['id'], $plan['label'], $plan['months'], $plan['days'], (int) $plan['lifetime'],
            $plan['amount_kes'], (int) $plan['test'], (int) $plan['active'], $plan['sort_order'],
        ]);
        return [['success' => true, 'plans' => $this->all()], 200];
    }

    /** @return array{0: array, 1: int} */
    public function delete(string $id): array
    {
        $this->ensureTable();
        $delete = $this->pdo->prepare('DELETE FROM license_plans WHERE id = ?');
        $delete->execute([strtolower(trim($id))]);
        if ($delete->rowCount() !== 1) {
            return [['success' => false, 'message' => 'No such plan.'], 404];
        }
        return [['success' => true, 'plans' => $this->all()], 200];
    }

    // ------------------------------------------------------------------ the table

    /**
     * Creates the table when this database does not have it yet and, ONLY then, fills
     * it with the starting set from config. Same "ask once, leave a note" idea as the
     * other ensure* checks: the note stops every request from repeating the question.
     */
    public function ensureTable(): void
    {
        if (self::$tableChecked) {
            return;
        }
        $name = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $note = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nexapos_license_plans_table_' . sha1($name);
        $noteTime = @filemtime($note);
        if ($noteTime !== false && (time() - $noteTime) < 6 * 3600) {
            self::$tableChecked = true;
            return;
        }
        $existed = (bool) $this->pdo->query("SHOW TABLES LIKE 'license_plans'")->fetchColumn();
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS license_plans (
                id VARCHAR(20) NOT NULL PRIMARY KEY,
                label VARCHAR(60) NOT NULL,
                months INT NOT NULL DEFAULT 0,
                days INT NOT NULL DEFAULT 0,
                lifetime TINYINT(1) NOT NULL DEFAULT 0,
                amount_kes INT NOT NULL,
                is_test TINYINT(1) NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 100
            )'
        );
        if (!$existed) {
            $order = 10;
            foreach ((array) ($this->config['plans'] ?? []) as $seed) {
                if (!is_array($seed)) {
                    continue;
                }
                [$plan] = self::validate($seed + ['sort_order' => $order]);
                $order += 10;
                if ($plan === null) {
                    continue; // a malformed starting plan is left out rather than sold wrongly
                }
                $this->pdo->prepare(
                    'INSERT IGNORE INTO license_plans (id, label, months, days, lifetime, amount_kes, is_test, active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $plan['id'], $plan['label'], $plan['months'], $plan['days'], (int) $plan['lifetime'],
                    $plan['amount_kes'], (int) $plan['test'], (int) $plan['active'], $plan['sort_order'],
                ]);
            }
        }
        self::$tableChecked = true;
        @file_put_contents($note, gmdate('c'));
    }

    // ------------------------------------------------------------------ rules

    /**
     * @return array{0: ?array, 1: string} the cleaned plan, or null and what is wrong with it
     */
    public static function validate(array $body): array
    {
        $id = strtolower(trim((string) ($body['id'] ?? '')));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,19}$/', $id) !== 1) {
            return [null, 'The plan code must be 1-20 letters, digits, "-" or "_" (for example: m6 or lifetime).'];
        }
        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > self::MAX_LABEL || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            return [null, 'Give the plan a name of up to ' . self::MAX_LABEL . ' characters (this is what customers see).'];
        }
        $lifetime = !empty($body['lifetime']);
        $months = self::whole($body['months'] ?? 0);
        $days = self::whole($body['days'] ?? 0);
        if ($lifetime) {
            $months = 0;
            $days = 0;
        } else {
            if ($months === null || $days === null || $months < 0 || $days < 0 || $months > self::MAX_MONTHS || $days > self::MAX_DAYS) {
                return [null, 'Months must be 0-' . self::MAX_MONTHS . ' and days 0-' . self::MAX_DAYS . '.'];
            }
            if ($months === 0 && $days === 0) {
                return [null, 'Say how long the plan lasts (months and/or days), or make it a lifetime plan.'];
            }
        }
        $amount = self::whole($body['amount_kes'] ?? null);
        if ($amount === null || $amount < 1 || $amount > self::MAX_PRICE_KES) {
            return [null, 'The price must be a whole number of shillings from 1 to ' . number_format(self::MAX_PRICE_KES) . '.'];
        }
        $sort = self::whole($body['sort_order'] ?? 100);
        if ($sort === null || $sort < -1000 || $sort > 1000) {
            return [null, 'The order must be a number from -1000 to 1000.'];
        }
        return [[
            'id' => $id,
            'label' => $label,
            'months' => $months,
            'days' => $days,
            'lifetime' => $lifetime,
            'amount_kes' => $amount,
            'test' => !empty($body['test']),
            'active' => !array_key_exists('active', $body) || !empty($body['active']),
            'sort_order' => $sort,
        ], ''];
    }

    private static function whole(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,9}$/', trim($value)) === 1) {
            return (int) trim($value);
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e9) {
            return (int) $value;
        }
        return null;
    }

    /** A row as the rest of the server (and the vendor's screen) sees it. */
    public static function normalize(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'label' => (string) $row['label'],
            'months' => (int) $row['months'],
            'days' => (int) $row['days'],
            'lifetime' => (int) $row['lifetime'] === 1,
            'amount_kes' => (int) $row['amount_kes'],
            'test' => (int) $row['is_test'] === 1,
            'active' => (int) $row['active'] === 1,
            'sort_order' => (int) $row['sort_order'],
        ];
    }
}
