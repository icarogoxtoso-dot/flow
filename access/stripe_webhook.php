<?php
require_once __DIR__ . '/../secure/config.php';
require_once __DIR__ . '/../secure/stripe.php';

header('Content-Type: application/json; charset=utf-8');

function webhookFail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function timingSafeEquals(string $a, string $b): bool
{
    if (function_exists('hash_equals')) {
        return hash_equals($a, $b);
    }
    if (strlen($a) !== strlen($b)) {
        return false;
    }
    $res = 0;
    for ($i = 0; $i < strlen($a); $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

function verifyStripeSignature(string $payload, string $sigHeader, string $secret, int $tolerance = 300): bool
{
    $parts = [];
    foreach (explode(',', $sigHeader) as $chunk) {
        $kv = explode('=', trim($chunk), 2);
        if (count($kv) === 2) {
            $parts[$kv[0]][] = $kv[1];
        }
    }

    $ts = (int) (($parts['t'][0] ?? '') ?: 0);
    if ($ts <= 0) {
        return false;
    }

    if (abs(time() - $ts) > $tolerance) {
        return false;
    }

    $signedPayload = $ts . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    $signatures = $parts['v1'] ?? [];
    foreach ($signatures as $sig) {
        if (timingSafeEquals($expected, $sig)) {
            return true;
        }
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    webhookFail('Método não permitido.', 405);
}

$secret = (string) envValue('STRIPE_WEBHOOK_SECRET', '');
if ($secret === '') {
    webhookFail('Webhook não configurado.', 500);
}

$payload = (string) file_get_contents('php://input');
$sigHeader = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
if ($sigHeader === '' || !verifyStripeSignature($payload, $sigHeader, $secret, 300)) {
    webhookFail('Assinatura inválida.', 400);
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    webhookFail('Payload inválido.', 400);
}

$type = (string) ($event['type'] ?? '');
$object = $event['data']['object'] ?? null;
if (!is_array($object)) {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$cfg = appConfig();
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
    webhookFail('Banco indisponível.', 500);
}

function updateUserSubscription(PDO $pdo, int $userId, string $customerId, string $subscriptionId, string $status, ?int $currentPeriodEnd): void
{
    $pdo->prepare(
        'UPDATE usuarios
         SET stripe_customer_id = COALESCE(NULLIF(:customer_id, \'\'), stripe_customer_id),
             stripe_subscription_id = :subscription_id,
             subscription_status = :status,
             current_period_end = :current_period_end
         WHERE id = :id
         LIMIT 1'
    )->execute([
        ':customer_id' => $customerId,
        ':subscription_id' => $subscriptionId,
        ':status' => $status,
        ':current_period_end' => $currentPeriodEnd,
        ':id' => $userId,
    ]);
}

try {
    if ($type === 'checkout.session.completed') {
        $userId = (int) ($object['client_reference_id'] ?? 0);
        $customerId = (string) ($object['customer'] ?? '');
        $subscriptionId = (string) ($object['subscription'] ?? '');

        if ($userId > 0 && $subscriptionId !== '') {
            $subRes = stripeApiRequest('GET', '/v1/subscriptions/' . rawurlencode($subscriptionId));
            $status = 'active';
            $periodEnd = null;
            if ($subRes['ok']) {
                $status = (string) ($subRes['data']['status'] ?? $status);
                $periodEnd = isset($subRes['data']['current_period_end']) ? (int) $subRes['data']['current_period_end'] : null;
            }
            updateUserSubscription($pdo, $userId, $customerId, $subscriptionId, $status, $periodEnd);
        }
    } elseif ($type === 'customer.subscription.updated' || $type === 'customer.subscription.deleted') {
        $subscriptionId = (string) ($object['id'] ?? '');
        $customerId = (string) ($object['customer'] ?? '');
        $status = (string) ($object['status'] ?? '');
        $periodEnd = isset($object['current_period_end']) ? (int) $object['current_period_end'] : null;

        if ($subscriptionId !== '') {
            $uStmt = $pdo->prepare('SELECT id FROM usuarios WHERE stripe_subscription_id = :sid LIMIT 1');
            $uStmt->execute([':sid' => $subscriptionId]);
            $row = $uStmt->fetch();
            if ($row) {
                updateUserSubscription($pdo, (int) $row['id'], $customerId, $subscriptionId, ($status !== '' ? $status : 'canceled'), $periodEnd);
            }
        }
    } elseif ($type === 'invoice.paid') {
        $subscriptionId = (string) ($object['subscription'] ?? '');
        $customerId = (string) ($object['customer'] ?? '');
        if ($subscriptionId !== '') {
            $uStmt = $pdo->prepare('SELECT id FROM usuarios WHERE stripe_subscription_id = :sid LIMIT 1');
            $uStmt->execute([':sid' => $subscriptionId]);
            $row = $uStmt->fetch();
            if ($row) {
                $subRes = stripeApiRequest('GET', '/v1/subscriptions/' . rawurlencode($subscriptionId));
                $status = 'active';
                $periodEnd = null;
                if ($subRes['ok']) {
                    $status = (string) ($subRes['data']['status'] ?? $status);
                    $periodEnd = isset($subRes['data']['current_period_end']) ? (int) $subRes['data']['current_period_end'] : null;
                }
                updateUserSubscription($pdo, (int) $row['id'], $customerId, $subscriptionId, $status, $periodEnd);
            }
        }
    }
} catch (PDOException $e) {
    $msg = (string) $e->getMessage();
    if (stripos($msg, 'unknown column') !== false) {
        webhookFail('Banco desatualizado para Stripe. Rode a migração (scripts/migrations/001_init.sql).', 500);
    }
    webhookFail('Falha ao processar webhook.', 500);
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
