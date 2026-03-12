<?php
require_once __DIR__ . '/../secure/auth.php';
require_once __DIR__ . '/../secure/config.php';
require_once __DIR__ . '/../secure/stripe.php';

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

$cfg = appConfig();
$priceId = trim((string) envValue('STRIPE_PRICE_ID', ''));
if ($priceId === '') {
    jsonFail('Stripe não configurado (price).', 500);
}

$userId = (int) (currentUserId() ?? 0);
if ($userId <= 0) {
    jsonFail('Sessão inválida.', 401);
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
} catch (PDOException $e) {
    jsonFail('Banco indisponível no momento.', 500);
}

try {
    $uStmt = $pdo->prepare('SELECT id, email, nome, stripe_customer_id FROM usuarios WHERE id = :id LIMIT 1');
    $uStmt->execute([':id' => $userId]);
    $u = $uStmt->fetch();
    if (!$u) {
        jsonFail('Usuário não encontrado.', 404);
    }
} catch (PDOException $e) {
    $msg = (string) $e->getMessage();
    if (stripos($msg, 'unknown column') !== false) {
        jsonFail('Banco desatualizado: rode a migração (scripts/migrations/001_init.sql) para adicionar colunas do Stripe.', 500);
    }
    jsonFail('Falha ao carregar usuário.', 500);
}

$email = (string) ($u['email'] ?? '');
$name = (string) ($u['nome'] ?? '');
$stripeCustomerId = trim((string) ($u['stripe_customer_id'] ?? ''));

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

    try {
        $pdo->prepare('UPDATE usuarios SET stripe_customer_id = :cid WHERE id = :id LIMIT 1')
            ->execute([':cid' => $stripeCustomerId, ':id' => $userId]);
    } catch (PDOException $e) {
        jsonFail('Falha ao salvar cliente Stripe.', 500);
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

$url = (string) ($sessionRes['data']['url'] ?? '');
if ($url === '') {
    jsonFail('Resposta inválida do Stripe (session url).', 502);
}

echo json_encode(['ok' => true, 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
