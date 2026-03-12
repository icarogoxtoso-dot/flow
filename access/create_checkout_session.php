<?php
require_once __DIR__ . '/../secure/auth.php';
require_once __DIR__ . '/../secure/config.php';
require_once __DIR__ . '/../secure/stripe.php';
require_once __DIR__ . '/../secure/stripe_db.php';

startSecureSession();
header('Content-Type: application/json; charset=utf-8');

function jsonFail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonFail('Método não permitido.', 405);
}

if (!isAuthenticated()) {
    jsonFail('Faça login para continuar.', 401);
}

$userId = (int) (currentUserId() ?? 0);
if ($userId <= 0) {
    jsonFail('Sessão inválida.', 401);
}

$requireSubscription = envBool('REQUIRE_SUBSCRIPTION', true);

$saveProfileUrl = appPath('/secure/save_profile.php');
if (currentUserIsAdmin()) {
    echo json_encode(['ok' => true, 'url' => $saveProfileUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$requireSubscription) {
    echo json_encode(['ok' => true, 'url' => $saveProfileUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$cfg = appConfig();
$priceId = trim((string) envValue('STRIPE_PRICE_ID', ''));
if ($priceId === '') {
    jsonFail('Stripe não configurado (price).', 500);
}

$host = $cfg['db_host'];
$db = $cfg['db_name'];
$user = $cfg['db_user'];
$pass = $cfg['db_pass'];
$charset = $cfg['db_charset'];

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, $options);
    ensureStripeTablesOrFail($pdo);
} catch (PDOException $e) {
    jsonFail('Banco indisponível no momento.', 500);
} catch (RuntimeException $e) {
    jsonFail($e->getMessage(), 500);
}

try {
    if (userCanCreateProfile($pdo, $userId)) {
        echo json_encode(['ok' => true, 'url' => $saveProfileUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $uStmt = $pdo->prepare('SELECT id, email, nome FROM usuarios WHERE id = :id LIMIT 1');
    $uStmt->execute([':id' => $userId]);
    $u = $uStmt->fetch();
    if (!$u) {
        jsonFail('Usuário não encontrado.', 404);
    }
} catch (PDOException $e) {
    jsonFail('Falha ao carregar usuário.', 500);
}

$email = (string) ($u['email'] ?? '');
$name = (string) ($u['nome'] ?? '');

$existingSub = null;
$stripeCustomerId = '';
try {
    $existingSub = getUserSubscription($pdo, $userId);
    if (is_array($existingSub)) {
        $stripeCustomerId = trim((string) ($existingSub['stripe_customer_id'] ?? ''));
    }
} catch (PDOException $e) {
    $existingSub = null;
}

if ($stripeCustomerId === '') {
    $customerRes = stripeApiRequest('POST', '/v1/customers', [
        'email' => $email,
        'name' => $name,
        'metadata[user_id]' => (string) $userId,
    ]);
    if (!$customerRes['ok']) {
        jsonFail('Não foi possível criar o cliente no Stripe.', 502);
    }
    $stripeCustomerId = (string) ($customerRes['data']['id'] ?? '');
    if ($stripeCustomerId === '') {
        jsonFail('Resposta inválida do Stripe (customer).', 502);
    }
}

$base = appBaseUrl();
$successUrl = $base . appPath('/access/checkout_success.php') . '?session_id={CHECKOUT_SESSION_ID}';
$cancelUrl = $base . appPath('/access/checkout_cancel.php');

$sessionRes = stripeApiRequest('POST', '/v1/checkout/sessions', [
    'mode' => 'subscription',
    'customer' => $stripeCustomerId,
    'line_items[0][price]' => $priceId,
    'line_items[0][quantity]' => '1',
    'allow_promotion_codes' => 'true',
    'success_url' => $successUrl,
    'cancel_url' => $cancelUrl,
    'client_reference_id' => (string) $userId,
    'metadata[user_id]' => (string) $userId,
]);

if (!$sessionRes['ok']) {
    jsonFail('Não foi possível iniciar o checkout.', 502);
}

$checkoutSessionId = (string) ($sessionRes['data']['id'] ?? '');
$url = (string) ($sessionRes['data']['url'] ?? '');
if ($checkoutSessionId === '' || $url === '') {
    jsonFail('Resposta inválida do Stripe (session).', 502);
}

try {
    if (is_array($existingSub) && (int) ($existingSub['id'] ?? 0) > 0) {
        $upd = $pdo->prepare(
            "UPDATE subscriptions
             SET user_id = :user_id,
                 email = :email,
                 stripe_customer_id = :customer_id,
                 stripe_checkout_session_id = :session_id,
                 stripe_subscription_id = NULL,
                 subscription_status = 'incomplete',
                 current_period_end = NULL
             WHERE id = :id
             LIMIT 1"
        );
        $upd->execute([
            ':user_id' => $userId,
            ':email' => $email,
            ':customer_id' => $stripeCustomerId,
            ':session_id' => $checkoutSessionId,
            ':id' => (int) $existingSub['id'],
        ]);
    } else {
        $ins = $pdo->prepare(
            "INSERT INTO subscriptions (user_id, email, stripe_customer_id, stripe_checkout_session_id, subscription_status)
             VALUES (:user_id, :email, :customer_id, :session_id, 'incomplete')"
        );
        $ins->execute([
            ':user_id' => $userId,
            ':email' => $email,
            ':customer_id' => $stripeCustomerId,
            ':session_id' => $checkoutSessionId,
        ]);
    }
} catch (PDOException $e) {
    jsonFail('Falha ao salvar sessão de checkout.', 500);
}

echo json_encode(['ok' => true, 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
