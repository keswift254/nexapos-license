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
];

$localConfig = __DIR__ . '/license.local.php';
if (is_file($localConfig)) {
    $config = array_replace_recursive($config, require $localConfig);
}

return $config;
