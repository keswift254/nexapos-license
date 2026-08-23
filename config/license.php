<?php

// admin_secret must come from license.local.php (gitignored) - never
// hardcode a real value here. Whoever holds this can mint license keys,
// so treat it like the payments platform's Paystack secret key: never
// paste it in chat, never commit it.
$config = [
    'admin_secret' => getenv('LICENSE_ADMIN_SECRET') ?: '',
    // How long a generated code stays redeemable via activate before it
    // expires unused - the activation *window*, not the license's own
    // lifetime (a code that's been activated never expires on its own;
    // only an explicit revoke ends it).
    'key_expiry_minutes' => (int) (getenv('LICENSE_KEY_EXPIRY_MINUTES') ?: 30),
    // Registration-notification email (see app/Services/Mailer.php) -
    // all optional; register_lead just logs and moves on if unset or if
    // sending fails, since a lead is already saved by the time this
    // runs and must never be lost over a mail hiccup.
    'smtp_host' => getenv('SMTP_HOST') ?: '',
    'smtp_port' => (int) (getenv('SMTP_PORT') ?: 587),
    'smtp_user' => getenv('SMTP_USER') ?: '',
    'smtp_pass' => getenv('SMTP_PASS') ?: '',
    'smtp_from' => getenv('SMTP_FROM') ?: '',
    'smtp_from_name' => getenv('SMTP_FROM_NAME') ?: 'NexaPOS',
    'notify_email' => getenv('NOTIFY_EMAIL') ?: 'condojuniur@outlook.com',
];

$localConfig = __DIR__ . '/license.local.php';
if (is_file($localConfig)) {
    $config = array_replace_recursive($config, require $localConfig);
}

return $config;
