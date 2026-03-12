<?php
require_once __DIR__ . '/../secure/config.php';
require_once __DIR__ . '/../secure/stripe.php';
require_once __DIR__ . '/../secure/auth.php';

startSecureSession();

$priceId = stripePriceId();
if ($priceId === '') {
    http_response_code(500);
    echo 'Configuracao Stripe incompleta (STRIPE_PRICE_ID ausente).';
    exit;
}

$successUrl = stripeSuccessUrl();
$cancelUrl = stripeCancelUrl();

$params = [
    'mode' => 'subscription',
    'line_items[0][price]' => $priceId,
    'line_items[0][quantity]' => 1,
    'success_url' => $successUrl,
    'cancel_url' => $cancelUrl,
    'allow_promotion_codes' => 'true',
    // Checkout coleta e-mail; usaremos no webhook para vincular ao cadastro.
    'billing_address_collection' => 'auto',
];

if (isAuthenticated()) {
    $uid = (int) (currentUserId() ?? 0);
    if ($uid > 0) {
        $params['client_reference_id'] = (string) $uid;
        $params['subscription_data[metadata][user_id]'] = (string) $uid;
    }
}

$resp = stripeApiRequest('POST', '/v1/checkout/sessions', $params);

if (empty($resp['ok'])) {
    http_response_code(500);
    echo 'Não foi possível iniciar o pagamento.';
    exit;
}

$session = $resp['data'] ?? [];
$url = is_array($session) ? (string) ($session['url'] ?? '') : '';
if ($url === '') {
    http_response_code(500);
    echo 'Não foi possível redirecionar para o checkout.';
    exit;
}

header('Location: ' . $url);
exit;
