<?php
// A stand-in for api.paystack.co, for the license server's tests ONLY. Nothing here
// talks to the real Paystack. State lives in a JSON file next to the temp dir.
//   POST /transaction/initialize      (Bearer sk_test_fake_key)  -> checkout URL
//   GET  /transaction/verify/{ref}                                -> current state
//   POST /_test/set     {reference,status,amount?,currency?}      -> "customer paid" etc.
//   GET  /_test/payload?reference=                                -> what initialize received
//   POST /_test/mode    {fail_initialize?:bool, verify_500?:bool}
$stateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fake_paystack_state_' . ($_SERVER['SERVER_PORT'] ?? '0') . '.json';
$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$state += ['tx' => [], 'mode' => ['fail_initialize' => false, 'verify_500' => false], 'verify_calls' => 0];
$save = function () use (&$state, $stateFile) { file_put_contents($stateFile, json_encode($state)); };
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
header('Content-Type: application/json');
$out = function (array $payload, int $status = 200) { http_response_code($status); echo json_encode($payload); exit; };

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($path, '/transaction/') && $auth !== 'Bearer sk_test_fake_key') {
    $out(['status' => false, 'message' => 'Invalid key'], 401);
}

if ($path === '/transaction/initialize' && $method === 'POST') {
    if ($state['mode']['fail_initialize']) {
        $state['mode']['fail_initialize'] = false;
        $save();
        $out(['status' => false, 'message' => 'Currency not supported by merchant'], 400);
    }
    $ref = (string) ($body['reference'] ?? '');
    $state['tx'][$ref] = ['payload' => $body, 'status' => 'abandoned', 'amount' => (int) ($body['amount'] ?? 0), 'currency' => (string) ($body['currency'] ?? 'NGN')];
    $save();
    $out(['status' => true, 'message' => 'Authorization URL created', 'data' => [
        'authorization_url' => 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/pay/' . $ref, 'access_code' => 'ac_fake', 'reference' => $ref]]);
}

if (str_starts_with($path, '/transaction/verify/') && $method === 'GET') {
    $state['verify_calls']++;
    $save();
    if ($state['mode']['verify_500']) {
        $out(['status' => false, 'message' => 'Internal error'], 500);
    }
    $ref = rawurldecode(substr($path, strlen('/transaction/verify/')));
    if (!isset($state['tx'][$ref])) {
        $out(['status' => false, 'message' => 'Transaction reference not found'], 404);
    }
    $tx = $state['tx'][$ref];
    $out(['status' => true, 'message' => 'Verification successful', 'data' => [
        'status' => $tx['status'], 'amount' => $tx['amount'], 'currency' => $tx['currency'], 'reference' => $ref]]);
}

if ($path === '/_test/set' && $method === 'POST') {
    $ref = (string) ($body['reference'] ?? '');
    if (!isset($state['tx'][$ref])) { $out(['ok' => false], 404); }
    foreach (['status', 'amount', 'currency'] as $key) {
        if (array_key_exists($key, $body)) { $state['tx'][$ref][$key] = $body[$key]; }
    }
    $save();
    $out(['ok' => true]);
}
if ($path === '/_test/payload' && $method === 'GET') {
    $out($state['tx'][(string) ($_GET['reference'] ?? '')]['payload'] ?? []);
}
if ($path === '/_test/verify_calls' && $method === 'GET') {
    $out(['verify_calls' => $state['verify_calls']]);
}
if ($path === '/_test/mode' && $method === 'POST') {
    $state['mode'] = array_merge($state['mode'], $body);
    $save();
    $out(['ok' => true]);
}
$out(['status' => false, 'message' => 'not found: ' . $path], 404);
