<?php
require_once __DIR__ . '/config.php';

function stripeApiKey(): string
{
    return trim((string) envValue('STRIPE_SECRET_KEY', ''));
}

function stripeWebhookSecret(): string
{
    return trim((string) envValue('STRIPE_WEBHOOK_SECRET', ''));
}

function stripePriceId(): string
{
    return trim((string) envValue('STRIPE_PRICE_ID', ''));
}

function stripeSuccessUrl(): string
{
    $cfg = appConfig();
    $default = appBaseUrl() . appPath('/access/subscribe_success.php');
    $raw = trim((string) envValue('STRIPE_SUCCESS_URL', $default));
    if ($raw === '') {
        return $default;
    }
    if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
        return $raw;
    }
    return appBaseUrl() . $raw;
}

function stripeCancelUrl(): string
{
    $cfg = appConfig();
    $default = appBaseUrl() . appPath('/access/subscribe_cancel.php');
    $raw = trim((string) envValue('STRIPE_CANCEL_URL', $default));
    if ($raw === '') {
        return $default;
    }
    if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
        return $raw;
    }
    return appBaseUrl() . $raw;
}

function stripeApiRequest(string $method, string $path, array $params = [], array $headers = []): array
{
    $apiKey = stripeApiKey();
    if ($apiKey === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Stripe secret key ausente.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'error' => 'cURL indisponivel no servidor.'];
    }

    $method = strtoupper(trim($method));
    $url = 'https://api.stripe.com' . $path;
    $body = '';

    if ($method === 'GET' && !empty($params)) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    } elseif ($method !== 'GET') {
        $body = http_build_query($params);
    }

    $ch = curl_init($url);
    $baseHeaders = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/x-www-form-urlencoded',
        'Stripe-Version: 2023-10-16',
    ];
    foreach ($headers as $h) {
        $baseHeaders[] = $h;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $baseHeaders,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 20,
    ]);

    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'status' => $status, 'error' => $curlErr !== '' ? $curlErr : 'Falha ao chamar Stripe.'];
    }

    $decoded = json_decode((string) $resp, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => $status, 'error' => 'Resposta invalida da Stripe.'];
    }

    if ($status < 200 || $status >= 300) {
        $msg = (string) (($decoded['error']['message'] ?? '') ?: 'Erro Stripe');
        return ['ok' => false, 'status' => $status, 'error' => $msg, 'raw' => $decoded];
    }

    return ['ok' => true, 'status' => $status, 'data' => $decoded];
}

function stripeVerifyWebhookSignature(string $payload, string $sigHeader, string $secret, int $toleranceSeconds = 300): bool
{
    $sigHeader = trim($sigHeader);
    if ($sigHeader === '' || $secret === '') {
        return false;
    }

    $parts = array_filter(array_map('trim', explode(',', $sigHeader)));
    $timestamp = null;
    $signatures = [];

    foreach ($parts as $part) {
        if (!str_contains($part, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $part, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k === 't') {
            $timestamp = ctype_digit($v) ? (int) $v : null;
        } elseif ($k === 'v1') {
            $signatures[] = $v;
        }
    }

    if ($timestamp === null || empty($signatures)) {
        return false;
    }

    $now = time();
    if (abs($now - $timestamp) > max(1, $toleranceSeconds)) {
        return false;
    }

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);

    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }

    return false;
}

function stripeUnixToDateTime(?int $unix): ?string
{
    if (!$unix || $unix <= 0) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $unix);
}

