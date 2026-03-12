<?php
require_once __DIR__ . '/../secure/config.php';
require_once __DIR__ . '/../secure/stripe.php';
require_once __DIR__ . '/../secure/stripe_db.php';

// Stripe recomenda usar o corpo bruto para verificar assinatura.
$payload = (string) file_get_contents('php://input');
$sigHeader = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$secret = stripeWebhookSecret();

if (!stripeVerifyWebhookSignature($payload, $sigHeader, $secret)) {
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$eventId = (string) ($event['id'] ?? '');
$eventType = (string) ($event['type'] ?? '');
$object = $event['data']['object'] ?? null;
if (!is_array($object)) {
    http_response_code(200);
    echo 'ok';
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
    ensureStripeTables($pdo);

    if ($eventId !== '' && webhookEventAlreadyProcessed($pdo, $eventId)) {
        http_response_code(200);
        echo 'ok';
        exit;
    }

    $upsert = function (array $data) use ($pdo): void {
        $stripeSubscriptionId = trim((string) ($data['stripe_subscription_id'] ?? ''));
        $stripeCustomerId = trim((string) ($data['stripe_customer_id'] ?? ''));
        $stripeCheckoutSessionId = trim((string) ($data['stripe_checkout_session_id'] ?? ''));
        $userId = (int) ($data['user_id'] ?? 0);

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $status = trim((string) ($data['subscription_status'] ?? ''));
        $periodEnd = trim((string) ($data['current_period_end'] ?? ''));

        if ($stripeSubscriptionId === '' && $stripeCustomerId === '' && $stripeCheckoutSessionId === '') {
            return;
        }

        $select = $pdo->prepare(
            "SELECT id FROM subscriptions
             WHERE (stripe_subscription_id = :sub_id AND :sub_id <> '')
                OR (stripe_customer_id = :cus_id AND :cus_id <> '')
                OR (stripe_checkout_session_id = :sess_id AND :sess_id <> '')
             ORDER BY id DESC
             LIMIT 1"
        );
        $select->execute([
            ':sub_id' => $stripeSubscriptionId,
            ':cus_id' => $stripeCustomerId,
            ':sess_id' => $stripeCheckoutSessionId,
        ]);
        $existingId = (int) ($select->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $stmt = $pdo->prepare(
                "UPDATE subscriptions
                 SET user_id = CASE WHEN :user_id > 0 AND (user_id IS NULL OR user_id = 0) THEN :user_id ELSE user_id END,
                     email = COALESCE(NULLIF(:email, ''), email),
                     stripe_customer_id = COALESCE(NULLIF(:cus_id, ''), stripe_customer_id),
                     stripe_subscription_id = COALESCE(NULLIF(:sub_id, ''), stripe_subscription_id),
                     stripe_checkout_session_id = COALESCE(NULLIF(:sess_id, ''), stripe_checkout_session_id),
                     subscription_status = COALESCE(NULLIF(:status, ''), subscription_status),
                     current_period_end = COALESCE(NULLIF(:period_end, ''), current_period_end)
                 WHERE id = :id"
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':email' => $email,
                ':cus_id' => $stripeCustomerId,
                ':sub_id' => $stripeSubscriptionId,
                ':sess_id' => $stripeCheckoutSessionId,
                ':status' => $status,
                ':period_end' => $periodEnd,
                ':id' => $existingId,
            ]);
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO subscriptions (user_id, email, stripe_customer_id, stripe_subscription_id, stripe_checkout_session_id, subscription_status, current_period_end)
             VALUES (:user_id, :email, :cus_id, :sub_id, :sess_id, :status, :period_end)"
        );
        $stmt->execute([
            ':user_id' => $userId > 0 ? $userId : null,
            ':email' => $email !== '' ? $email : null,
            ':cus_id' => $stripeCustomerId !== '' ? $stripeCustomerId : null,
            ':sub_id' => $stripeSubscriptionId !== '' ? $stripeSubscriptionId : null,
            ':sess_id' => $stripeCheckoutSessionId !== '' ? $stripeCheckoutSessionId : null,
            ':status' => $status !== '' ? $status : 'incomplete',
            ':period_end' => $periodEnd !== '' ? $periodEnd : null,
        ]);
    };

    $refreshSubscription = function (string $subscriptionId): array {
        $subscriptionId = trim($subscriptionId);
        if ($subscriptionId === '') {
            return [];
        }
        $resp = stripeApiRequest('GET', '/v1/subscriptions/' . rawurlencode($subscriptionId));
        if (empty($resp['ok']) || !is_array($resp['data'] ?? null)) {
            return [];
        }
        return $resp['data'];
    };

    $handleSubscriptionObject = function (array $sub) use ($upsert): void {
        $userId = 0;
        if (isset($sub['metadata']['user_id']) && is_string($sub['metadata']['user_id']) && ctype_digit($sub['metadata']['user_id'])) {
            $userId = (int) $sub['metadata']['user_id'];
        }
        $upsert([
            'user_id' => $userId,
            'stripe_subscription_id' => (string) ($sub['id'] ?? ''),
            'stripe_customer_id' => (string) ($sub['customer'] ?? ''),
            'subscription_status' => (string) ($sub['status'] ?? ''),
            'current_period_end' => stripeUnixToDateTime(isset($sub['current_period_end']) ? (int) $sub['current_period_end'] : null),
        ]);
    };

    if ($eventType === 'checkout.session.completed') {
        $subscriptionId = (string) ($object['subscription'] ?? '');
        $customerId = (string) ($object['customer'] ?? '');
        $sessionId = (string) ($object['id'] ?? '');
        $email = (string) ($object['customer_details']['email'] ?? ($object['customer_email'] ?? ''));
        $userId = (int) ($object['client_reference_id'] ?? 0);

        $sub = $refreshSubscription($subscriptionId);
        if ($sub) {
            $upsert([
                'user_id' => $userId,
                'stripe_subscription_id' => (string) ($sub['id'] ?? $subscriptionId),
                'stripe_customer_id' => (string) ($sub['customer'] ?? $customerId),
                'stripe_checkout_session_id' => $sessionId,
                'email' => $email,
                'subscription_status' => (string) ($sub['status'] ?? ''),
                'current_period_end' => stripeUnixToDateTime(isset($sub['current_period_end']) ? (int) $sub['current_period_end'] : null),
            ]);
        } else {
            $upsert([
                'user_id' => $userId,
                'stripe_subscription_id' => $subscriptionId,
                'stripe_customer_id' => $customerId,
                'stripe_checkout_session_id' => $sessionId,
                'email' => $email,
            ]);
        }
    } elseif ($eventType === 'customer.subscription.updated' || $eventType === 'customer.subscription.deleted') {
        $userId = 0;
        if (isset($object['metadata']['user_id']) && is_string($object['metadata']['user_id']) && ctype_digit($object['metadata']['user_id'])) {
            $userId = (int) $object['metadata']['user_id'];
        }
        $upsert([
            'user_id' => $userId,
            'stripe_subscription_id' => (string) ($object['id'] ?? ''),
            'stripe_customer_id' => (string) ($object['customer'] ?? ''),
            'subscription_status' => (string) ($object['status'] ?? ''),
            'current_period_end' => stripeUnixToDateTime(isset($object['current_period_end']) ? (int) $object['current_period_end'] : null),
        ]);
    } elseif ($eventType === 'invoice.payment_succeeded' || $eventType === 'invoice.payment_failed') {
        $subscriptionId = (string) ($object['subscription'] ?? '');
        $sub = $refreshSubscription($subscriptionId);
        if ($sub) {
            $handleSubscriptionObject($sub);
        } elseif ($subscriptionId !== '') {
            $upsert([
                'stripe_subscription_id' => $subscriptionId,
                'stripe_customer_id' => (string) ($object['customer'] ?? ''),
            ]);
        }
    }

    if ($eventId !== '') {
        markWebhookEventProcessed($pdo, $eventId);
    }

    http_response_code(200);
    echo 'ok';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'error';
}
