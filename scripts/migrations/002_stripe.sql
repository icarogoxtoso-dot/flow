USE servicos_db;

CREATE TABLE IF NOT EXISTS subscriptions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(255) NOT NULL UNIQUE,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

