<?php

namespace License\Services;

/**
 * The vendor's own Paystack account, used to sell NexaPOS licenses from the
 * activation screen. Raw curl like Mailer, no SDK.
 *
 * The secret key (PAYSTACK_SECRET_KEY) lives only in this server's environment,
 * set by the vendor directly in the hosting dashboard - it is never sent to the
 * app, never stored in the database, never logged. The app only ever receives a
 * Paystack checkout URL.
 *
 * NOT the same thing as nexapos_platform's PaystackClient, which charges a
 * SHOP's customers with that shop's own keys. This one charges the shop OWNER
 * for NexaPOS itself, with the vendor's key, and settles into the vendor's
 * subaccount (PAYSTACK_SUBACCOUNT).
 */
class Paystack
{
    private string $secretKey;
    private string $subaccount;
    private string $baseUrl;

    public function __construct(array $config)
    {
        $this->secretKey = trim((string) ($config['paystack_secret_key'] ?? ''));
        $this->subaccount = trim((string) ($config['paystack_subaccount'] ?? ''));
        $this->baseUrl = rtrim((string) ($config['paystack_base_url'] ?? 'https://api.paystack.co'), '/');
    }

    /** Whether purchasing can work at all: without a key the plans are shown but cannot be paid for. */
    public function enabled(): bool
    {
        return $this->secretKey !== '';
    }

    public function subaccount(): string
    {
        return $this->subaccount;
    }

    /**
     * A webhook is genuine only if its body hashes (HMAC-SHA512, keyed with the
     * secret key) to the signature Paystack sent. Anyone can POST to a public
     * URL; this is the only thing that says the call came from Paystack.
     */
    public function validSignature(string $rawBody, string $signature): bool
    {
        if ($this->secretKey === '' || $signature === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha512', $rawBody, $this->secretKey), strtolower(trim($signature)));
    }

    /** @return array Paystack's decoded answer ({status, message, data}). */
    public function initialize(array $payload): array
    {
        if ($this->subaccount !== '') {
            $payload['subaccount'] = $this->subaccount;
        }
        return $this->call('POST', '/transaction/initialize', $payload);
    }

    /** @return array Paystack's decoded answer; status false + message when it does not know the reference. */
    public function verify(string $reference): array
    {
        return $this->call('GET', '/transaction/verify/' . rawurlencode($reference));
    }

    private function call(string $method, string $path, ?array $body = null): array
    {
        if ($this->secretKey === '') {
            throw new \RuntimeException('Paystack is not configured (PAYSTACK_SECRET_KEY).');
        }
        $ch = curl_init($this->baseUrl . $path);
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Could not reach Paystack: $error");
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Paystack sent back something unreadable (HTTP $httpCode).");
        }
        // A 4xx is an answer (bad request, unknown reference) the caller interprets;
        // a 5xx is Paystack having a bad moment - not something to act on.
        if ($httpCode >= 500) {
            throw new \RuntimeException("Paystack had an error (HTTP $httpCode).");
        }
        return $decoded;
    }
}
