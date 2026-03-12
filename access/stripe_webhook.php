<?php
require_once __DIR__ . '/../secure/config.php';
require_once __DIR__ . '/../secure/stripe.php';
require_once __DIR__ . '/../secure/stripe_db.php';

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

function tsToDatetime(?int $ts): ?string
{
    if (!is_int($ts) || $ts <= 0) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $ts);
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
    ensureStripeTablesOrFail($pdo);
} catch (PDOException $e) {
    webhookFail('Banco indisponível.', 500);
} catch (RuntimeException $e) {
    webhookFail($e->getMessage(), 500);
}

$eventId = trim((string) ($event['id'] ?? ''));
if ($eventId !== '' && webhookEventAlreadyProcessed($pdo, $eventId)) {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$type = (string) ($event['type'] ?? '');
$object = $event['data']['object'] ?? null;
if (!is_array($object)) {
    if ($eventId !== '') {
        markWebhookEventProcessed($pdo, $eventId);
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($type === 'checkout.session.completed') {
        $userId = (int) ($object['client_reference_id'] ?? 0);
        $customerId = (string) ($object['customer'] ?? '');
        $subscriptionId = (string) ($object['subscription'] ?? '');
        $checkoutSessionId = (string) ($object['id'] ?? '');
        $email = (string) ($object['customer_details']['email'] ?? ($object['customer_email'] ?? ''));

        if ($email === '' && $userId > 0) {
            $uStmt = $pdo->prepare('SELECT email FROM usuarios WHERE id = :id LIMIT 1');
            $uStmt->execute([':id' => $userId]);
            $email = (string) ($uStmt->fetchColumn() ?: '');
        }

        $status = 'active';
        $periodEnd = null;
        if ($subscriptionId !== '') {
            $subRes = stripeApiRequest('GET', '/v1/subscriptions/' . rawurlencode($subscriptionId));
            if ($subRes['ok']) {
                $status = (string) ($subRes['data']['status'] ?? $status);
                $periodEnd = tsToDatetime(isset($subRes['data']['current_period_end']) ? (int) $subRes['data']['current_period_end'] : null);
            }
        }

        $upsert = $pdo->prepare(
            "INSERT INTO subscriptions (user_id, email, stripe_customer_id, stripe_subscription_id, stripe_checkout_session_id, subscription_status, current_period_end)
             VALUES (:user_id, :email, :customer_id, :subscription_id, :session_id, :status, :period_end)
             ON DUPLICATE KEY UPDATE
                 user_id = VALUES(user_id),
                 email = VALUES(email),
                 stripe_customer_id = VALUES(stripe_customer_id),
                 stripe_subscription_id = VALUES(stripe_subscription_id),
                 stripe_checkout_session_id = VALUES(stripe_checkout_session_id),
                 subscription_status = VALUES(subscription_status),
                 current_period_end = VALUES(current_period_end),
                 updated_at = CURRENT_TIMESTAMP"
        );
        $upsert->execute([
            ':user_id' => $userId > 0 ? $userId : null,
            ':email' => $email !== '' ? strtolower(trim($email)) : null,
            ':customer_id' => $customerId !== '' ? $customerId : null,
            ':subscription_id' => $subscriptionId !== '' ? $subscriptionId : null,
            ':session_id' => $checkoutSessionId !== '' ? $checkoutSessionId : null,
            ':status' => $status !== '' ? $status : 'active',
            ':period_end' => $periodEnd,
        ]);
    } elseif ($type === 'customer.subscription.updated' || $type === 'customer.subscription.deleted') {
        $subscriptionId = (string) ($object['id'] ?? '');
        $customerId = (string) ($object['customer'] ?? '');
        $status = (string) ($object['status'] ?? '');
        $periodEnd = tsToDatetime(isset($object['current_period_end']) ? (int) $object['current_period_end'] : null);

        if ($subscriptionId !== '') {
            $upd = $pdo->prepare(
                "UPDATE subscriptions
                 SET stripe_customer_id = COALESCE(NULLIF(:customer_id, ''), stripe_customer_id),
                     subscription_status = :status,
                     current_period_end = :period_end,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE stripe_subscription_id = :subscription_id
                 LIMIT 1"
            );
            $upd->execute([
                ':customer_id' => $customerId,
                ':status' => $status !== '' ? $status : 'canceled',
                ':period_end' => $periodEnd,
                ':subscription_id' => $subscriptionId,
            ]);

            if ($upd->rowCount() === 0) {
                $email = null;
                $userId = null;
                if ($customerId !== '') {
                    $pick = $pdo->prepare('SELECT user_id, email FROM subscriptions WHERE stripe_customer_id = :customer_id LIMIT 1');
                    $pick->execute([':customer_id' => $customerId]);
                    $row = $pick->fetch(PDO::FETCH_ASSOC);
                    if (is_array($row)) {
                        $userId = isset($row['user_id']) ? (int) $row['user_id'] : null;
                        $email = isset($row['email']) ? (string) $row['email'] : null;
                    }
                }

                $ins = $pdo->prepare(
                    "INSERT INTO subscriptions (user_id, email, stripe_customer_id, stripe_subscription_id, subscription_status, current_period_end)
                     VALUES (:user_id, :email, :customer_id, :subscription_id, :status, :period_end)"
                );
                $ins->execute([
                    ':user_id' => ($userId ?? 0) > 0 ? $userId : null,
                    ':email' => is_string($email) && trim($email) !== '' ? strtolower(trim($email)) : null,
                    ':customer_id' => $customerId !== '' ? $customerId : null,
                    ':subscription_id' => $subscriptionId,
                    ':status' => $status !== '' ? $status : 'canceled',
                    ':period_end' => $periodEnd,
                ]);
            }
        }
    } elseif ($type === 'invoice.paid') {
        $subscriptionId = (string) ($object['subscription'] ?? '');
        if ($subscriptionId !== '') {
            $subRes = stripeApiRequest('GET', '/v1/subscriptions/' . rawurlencode($subscriptionId));
            $status = 'active';
            $periodEnd = null;
            $customerId = '';
            if ($subRes['ok']) {
                $status = (string) ($subRes['data']['status'] ?? $status);
                $customerId = (string) ($subRes['data']['customer'] ?? '');
                $periodEnd = tsToDatetime(isset($subRes['data']['current_period_end']) ? (int) $subRes['data']['current_period_end'] : null);
            }

            $upd = $pdo->prepare(
                "UPDATE subscriptions
                 SET stripe_customer_id = COALESCE(NULLIF(:customer_id, ''), stripe_customer_id),
                     subscription_status = :status,
                     current_period_end = :period_end,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE stripe_subscription_id = :subscription_id
                 LIMIT 1"
            );
            $upd->execute([
                ':customer_id' => $customerId,
                ':status' => $status,
                ':period_end' => $periodEnd,
                ':subscription_id' => $subscriptionId,
            ]);
        }
    }
} catch (PDOException $e) {
    webhookFail('Falha ao processar webhook.', 500);
}

if ($eventId !== '') {
    try {
        markWebhookEventProcessed($pdo, $eventId);
    } catch (PDOException $e) {
        // Ignore.
    }
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
