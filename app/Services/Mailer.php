<?php

namespace License\Services;

/**
 * Sends mail via Brevo's HTTP API (https://api.brevo.com/v3/smtp/email)
 * over plain curl - same raw-curl pattern nexapos_platform's
 * PaystackClient already uses, no Composer/SDK. NOT raw SMTP: Render
 * blocks outbound traffic to SMTP ports (25/465/587) on free web
 * services entirely, confirmed by a real "Connection timed out" in
 * production logs after configuring real Outlook SMTP credentials -
 * this is a platform-level block, not a credentials problem, and it
 * applies to every SMTP provider equally. An HTTP API sidesteps it
 * completely since it's just a normal HTTPS POST on port 443, the same
 * port everything else in this app already uses.
 */
class Mailer
{
    private string $apiKey;
    private string $fromEmail;
    private string $fromName;

    public function __construct(array $config)
    {
        $this->apiKey = (string) $config['brevo_api_key'];
        $this->fromEmail = (string) $config['mail_from'];
        $this->fromName = (string) ($config['mail_from_name'] ?? 'NexaPOS');
    }

    public function send(string $toEmail, string $subject, string $body): void
    {
        if ($this->apiKey === '' || $this->fromEmail === '') {
            throw new \RuntimeException('Mail is not configured (BREVO_API_KEY/MAIL_FROM).');
        }

        $payload = [
            'sender' => ['name' => $this->fromName, 'email' => $this->fromEmail],
            'to' => [['email' => $toEmail]],
            'subject' => $subject,
            'textContent' => $body,
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'api-key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Could not reach Brevo: $error");
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 300) {
            $decoded = json_decode((string) $raw, true);
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? $raw) : (string) $raw;
            throw new \RuntimeException("Brevo rejected the email (HTTP $httpCode): $message");
        }
    }
}
