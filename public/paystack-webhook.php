<?php

declare(strict_types=1);

// One Paystack account serves both NexaPOS license purchases and shop sales.
// Forward the exact signed request to the appropriate existing API handler.
// The receiving API verifies X-Paystack-Signature before touching a payment.
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > 1_048_576) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid webhook payload.']);
    exit;
}

$event = json_decode($raw, true);
if (!is_array($event)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid webhook JSON.']);
    exit;
}

$data = is_array($event['data'] ?? null) ? $event['data'] : [];
$reference = trim((string) ($data['reference'] ?? ''));
$metadata = $data['metadata'] ?? [];
if (is_string($metadata)) {
    $metadata = json_decode($metadata, true);
}
$metadata = is_array($metadata) ? $metadata : [];

// License checkouts generate nxl-<20 hex> references and product metadata.
// Shop checkouts carry source=NexaPOS. Prefer that explicit shop marker if a
// shop's own reference happens to start with nxl-.
$isShop = ($metadata['source'] ?? null) === 'NexaPOS';
$isLicense = !$isShop && (
    ($metadata['product'] ?? null) === 'NexaPOS license'
    || preg_match('/^nxl-[0-9a-f]{20}$/', $reference) === 1
);
$target = $isLicense
    ? 'https://license.nexapos.cc/index.php?action=paystack_webhook'
    : 'https://sync.nexapos.cc/index.php?action=paystack_webhook';

$signature = trim((string) ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? ''));
if (!function_exists('curl_init')) {
    error_log('[nexapos_webhook_router] PHP cURL is unavailable.');
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Webhook delivery unavailable.']);
    exit;
}

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $raw,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Paystack-Signature: ' . $signature,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 25,
]);
$answer = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($answer === false || $status < 200 || $status > 599) {
    error_log('[nexapos_webhook_router] Delivery failed: ' . $error);
    http_response_code(503); // Paystack retries instead of losing an event.
    echo json_encode(['success' => false, 'message' => 'Webhook delivery failed.']);
    exit;
}

// Preserve upstream status, especially 200 after processing and non-200 on
// rejection or temporary failure. Never acknowledge a failed delivery as paid.
http_response_code($status);
echo $answer;
