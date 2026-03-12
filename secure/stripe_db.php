<?php
require_once __DIR__ . '/config.php';

function ensureStripeTables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            email VARCHAR(190) NULL,
            stripe_customer_id VARCHAR(64) NULL UNIQUE,
            stripe_subscription_id VARCHAR(64) NULL UNIQUE,
            stripe_checkout_session_id VARCHAR(255) NULL UNIQUE,
            subscription_status VARCHAR(32) NOT NULL DEFAULT 'incomplete',
            current_period_end DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_subscriptions_user_id (user_id),
            INDEX idx_subscriptions_email (email),
            INDEX idx_subscriptions_status (subscription_status),
            INDEX idx_subscriptions_period_end (current_period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Se uma versao anterior criou user_id UNIQUE, remove para permitir historico/renovacoes.
    $idxStmt = $pdo->query("SHOW INDEX FROM subscriptions WHERE Column_name = 'user_id' AND Non_unique = 0");
    if ($idxStmt !== false) {
        $idx = $idxStmt->fetch(PDO::FETCH_ASSOC);
        $keyName = is_array($idx) ? (string) ($idx['Key_name'] ?? '') : '';
        if ($keyName !== '' && strtolower($keyName) !== 'primary') {
            $pdo->exec("ALTER TABLE subscriptions DROP INDEX `" . str_replace('`', '``', $keyName) . "`");
        }
    }

    $userIdxStmt = $pdo->query("SHOW INDEX FROM subscriptions WHERE Key_name = 'idx_subscriptions_user_id'");
    $hasUserIdx = $userIdxStmt !== false && (bool) $userIdxStmt->fetch(PDO::FETCH_ASSOC);
    if (!$hasUserIdx) {
        $pdo->exec("ALTER TABLE subscriptions ADD INDEX idx_subscriptions_user_id (user_id)");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stripe_webhook_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(255) NOT NULL UNIQUE,
            received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function subscriptionIsWithinPaidPeriod(?string $status, ?string $currentPeriodEnd): bool
{
    if (!is_string($status) || $status === '' || !is_string($currentPeriodEnd) || $currentPeriodEnd === '') {
        return false;
    }
    $allowed = ['active', 'past_due'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    return strtotime($currentPeriodEnd) > time();
}

function getUserSubscription(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT *
         FROM subscriptions
         WHERE user_id = :user_id
         ORDER BY COALESCE(current_period_end, '1970-01-01 00:00:00') DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function userCanCreateProfile(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM subscriptions s
         WHERE s.user_id = :user_id
           AND s.subscription_status IN ('active', 'past_due')
           AND s.current_period_end > NOW()
         LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    return (bool) $stmt->fetchColumn();
}

function claimLatestSubscriptionForEmail(PDO $pdo, int $userId, string $email): void
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return;
    }

    $pickStmt = $pdo->prepare(
        "SELECT id
         FROM subscriptions
         WHERE user_id IS NULL
           AND email = :email
         ORDER BY COALESCE(current_period_end, '1970-01-01 00:00:00') DESC, id DESC
         LIMIT 1"
    );
    $pickStmt->execute([':email' => $email]);
    $subId = (int) ($pickStmt->fetchColumn() ?: 0);
    if ($subId <= 0) {
        return;
    }

    $upd = $pdo->prepare("UPDATE subscriptions SET user_id = :user_id WHERE id = :id AND user_id IS NULL");
    $upd->execute([':user_id' => $userId, ':id' => $subId]);
}

function emailHasActiveSubscription(PDO $pdo, string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM subscriptions s
         WHERE s.email = :email
           AND s.subscription_status IN ('active', 'past_due')
           AND s.current_period_end > NOW()
         ORDER BY COALESCE(s.current_period_end, '1970-01-01 00:00:00') DESC, s.id DESC
         LIMIT 1"
    );
    $stmt->execute([':email' => $email]);
    return (bool) $stmt->fetchColumn();
}

function webhookEventAlreadyProcessed(PDO $pdo, string $eventId): bool
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return false;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM stripe_webhook_events WHERE event_id = :event_id LIMIT 1");
    $stmt->execute([':event_id' => $eventId]);
    return (bool) $stmt->fetchColumn();
}

function markWebhookEventProcessed(PDO $pdo, string $eventId): void
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return;
    }
    $stmt = $pdo->prepare("INSERT IGNORE INTO stripe_webhook_events (event_id) VALUES (:event_id)");
    $stmt->execute([':event_id' => $eventId]);
}
