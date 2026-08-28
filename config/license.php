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
    // runs and must never be lost over a mail hiccup. Brevo's HTTP API,
    // not raw SMTP - Render blocks outbound SMTP ports entirely on free
    // web services, confirmed via a real "Connection timed out" in
    // production after the SMTP version was actually configured and
    // deployed.
    'brevo_api_key' => getenv('BREVO_API_KEY') ?: '',
    'mail_from' => getenv('MAIL_FROM') ?: '',
    'mail_from_name' => getenv('MAIL_FROM_NAME') ?: 'NexaPOS',
    'notify_email' => getenv('NOTIFY_EMAIL') ?: 'condojuniur@outlook.com',
    // Lets revoke() also cut off a device's platform/sync access, not
    // just its license - see that action's own comment. Reuses
    // admin_secret above rather than a separate value: by the operator's
    // own deliberate choice, nexapos_platform's PLATFORM_ADMIN_SECRET is
    // already set to this exact same value (a genuinely separate env var
    // per service, just intentionally matching). If that ever changes on
    // one side without the other, this call just starts failing
    // harmlessly (see revoke()'s try/catch) rather than breaking the
    // license revoke itself.
    'platform_base_url' => getenv('NEXAPOS_PLATFORM_BASE_URL') ?: 'https://nexapos-platform.onrender.com/index.php',
];

$localConfig = __DIR__ . '/license.local.php';
if (is_file($localConfig)) {
    $config = array_replace_recursive($config, require $localConfig);
}

return $config;
