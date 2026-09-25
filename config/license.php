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

    // --- Selling licenses from the activation screen (app/Services/Purchases.php) ---
    // The vendor's OWN Paystack account. The secret key is set in the hosting
    // dashboard's environment only - never in a file in this repo, never pasted in
    // chat. Without it the plans are still listed but cannot be paid for, so the
    // app can ship before the account is ready.
    'paystack_secret_key' => getenv('PAYSTACK_SECRET_KEY') ?: '',
    // Where the money settles: the Paystack subaccount code (ACCT_...). Not a
    // secret, but the vendor's to provide. Empty = settles to the main account.
    'paystack_subaccount' => getenv('PAYSTACK_SUBACCOUNT') ?: '',
    // Only ever changed to point the tests at a fake Paystack.
    'paystack_base_url' => getenv('PAYSTACK_BASE_URL') ?: 'https://api.paystack.co',
    // This server's own public address: where Paystack sends the customer's
    // browser after paying (a "payment received" page), see payment_done.
    'public_base_url' => getenv('LICENSE_PUBLIC_BASE_URL') ?: 'https://nexapos-license-1.onrender.com/index.php',
    // The app polls for its payment every few seconds; Paystack is asked at most
    // this often per purchase. (An override exists only so the tests need not wait.)
    'purchase_verify_every_seconds' => (int) (getenv('PURCHASE_VERIFY_EVERY_SECONDS') !== false ? getenv('PURCHASE_VERIFY_EVERY_SECONDS') : 2),
    'brevo_base_url' => getenv('BREVO_BASE_URL') ?: 'https://api.brevo.com',
    // What is for sale. The SERVER decides prices and lengths: the app only
    // displays what it is told, and the amount charged is always the one stored
    // here, never one the app sends. `months` are calendar months from the moment
    // the customer receives the license. amount_kes is whole Kenya shillings.
    'plans' => [
        ['id' => 'm3', 'label' => '3 months', 'months' => 3, 'amount_kes' => 1500],
        ['id' => 'm6', 'label' => '6 months', 'months' => 6, 'amount_kes' => 3000],
        ['id' => 'm12', 'label' => '1 year', 'months' => 12, 'amount_kes' => 4800],
    ],
];

$localConfig = __DIR__ . '/license.local.php';
if (is_file($localConfig)) {
    $config = array_replace_recursive($config, require $localConfig);
}

return $config;
