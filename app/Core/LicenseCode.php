<?php

namespace License\Core;

use PDO;

/**
 * Generates the code a customer types into the app to activate it.
 * Mirrors nexapos_platform's InviteCode exactly - same ambiguity-free
 * alphabet, since this is meant to be read off one screen (the key
 * generator, or a message to the customer) and typed by hand into
 * another.
 */
class LicenseCode
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const LENGTH = 10;

    public static function generate(PDO $pdo): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = self::random();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM license_keys WHERE code = ?');
            $stmt->execute([$code]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not generate a unique license code.');
    }

    private static function random(): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }
        return $code;
    }
}
