<?php

namespace License\Services;

/**
 * Minimal hand-rolled SMTP client (EHLO/STARTTLS/AUTH LOGIN/DATA) - no
 * Composer/PHPMailer, matching every other backend in this project
 * family (see PaystackClient's own raw-curl approach in
 * nexapos_platform). Sends exactly one kind of email: "someone
 * registered" to the vendor. A caller must catch failures itself -
 * this never silently swallows anything, since a mail failure has to
 * be logged somewhere to be noticed, but it also must never be allowed
 * to fail the registration response itself (the lead is already saved
 * in the database by the time this runs).
 */
class Mailer
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;

    public function __construct(array $config)
    {
        $this->host = (string) $config['smtp_host'];
        $this->port = (int) $config['smtp_port'];
        $this->username = (string) $config['smtp_user'];
        $this->password = (string) $config['smtp_pass'];
        $this->fromEmail = (string) $config['smtp_from'];
        $this->fromName = (string) ($config['smtp_from_name'] ?? 'NexaPOS');
    }

    public function send(string $toEmail, string $subject, string $body): void
    {
        if ($this->host === '' || $this->username === '' || $this->password === '' || $this->fromEmail === '') {
            throw new \RuntimeException('SMTP is not configured (SMTP_HOST/SMTP_USER/SMTP_PASS/SMTP_FROM).');
        }

        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, 15);
        if ($socket === false) {
            throw new \RuntimeException("Could not connect to the SMTP server: $errstr");
        }

        // A hostname-shaped token to identify this client in EHLO - the
        // sending domain, not the SMTP server's own host. Servers rarely
        // validate this strictly, but it should look like something.
        $ehloIdentity = substr(strrchr($this->fromEmail, '@') ?: '@nexapos', 1) ?: 'nexapos';

        try {
            $this->expect($socket, '220');
            $this->command($socket, "EHLO $ehloIdentity", '250');
            $this->command($socket, 'STARTTLS', '220');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('Could not start TLS with the SMTP server.');
            }
            // Must re-EHLO after STARTTLS - the pre-TLS EHLO's capability
            // list isn't trusted (a MITM could have altered it in
            // plaintext), and some servers only advertise AUTH post-TLS.
            $this->command($socket, "EHLO $ehloIdentity", '250');
            $this->command($socket, 'AUTH LOGIN', '334');
            $this->command($socket, base64_encode($this->username), '334');
            $this->command($socket, base64_encode($this->password), '235');
            $this->command($socket, "MAIL FROM:<{$this->fromEmail}>", '250');
            $this->command($socket, "RCPT TO:<$toEmail>", '250');
            $this->command($socket, 'DATA', '354');

            $headers = [
                "From: {$this->fromName} <{$this->fromEmail}>",
                "To: <$toEmail>",
                'Subject: ' . $subject,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
            ];
            // A lone "." on a line ends DATA in the SMTP protocol - a
            // message body line that's just "." would be misread as the
            // terminator, so escape it per RFC 5321 dot-stuffing.
            $escapedBody = preg_replace('/^\./m', '..', $body);
            $message = implode("\r\n", $headers) . "\r\n\r\n" . $escapedBody . "\r\n.";
            $this->command($socket, $message, '250');
            $this->command($socket, 'QUIT', '221');
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function command($socket, string $line, string $expectedCode): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expectedCode);
    }

    /** @param resource $socket */
    private function expect($socket, string $expectedCode): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            // Multi-line SMTP replies use "250-" on every line but the
            // last, which uses "250 " (space, not dash) - stop reading
            // once we hit that.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if (!str_starts_with($response, $expectedCode)) {
            throw new \RuntimeException("Unexpected SMTP response (wanted $expectedCode): " . trim($response));
        }
    }
}
