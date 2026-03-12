<?php
require_once __DIR__ . '/../secure/auth.php';
require_once __DIR__ . '/../secure/config.php';
require_once __DIR__ . '/../secure/stripe_db.php';
startSecureSession();

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

function ensureUsersTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            telefone VARCHAR(20) NULL,
            senha_hash VARCHAR(255) NOT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $phoneColStmt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telefone'");
    $hasPhoneColumn = $phoneColStmt !== false && $phoneColStmt->fetch();
    if (!$hasPhoneColumn) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN telefone VARCHAR(20) NULL AFTER email");
    }
}

function ensurePasswordResetTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_resets_user (user_id),
            INDEX idx_password_resets_token (token_hash),
            CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function currentBaseUrl(): string
{
    return appBaseUrl();
}

function sendPasswordResetEmail(string $toEmail, string $toName, string $resetUrl): bool
{
    $cfg = appConfig();
    if (($cfg['mail_provider'] ?? '') !== 'resend') {
        return false;
    }

    $apiKey = (string) ($cfg['resend_api_key'] ?? '');
    $from = (string) ($cfg['mail_from'] ?? '');
    if ($apiKey === '' || $from === '' || !function_exists('curl_init')) {
        return false;
    }

    $nameSafe = trim($toName) !== '' ? $toName : 'usuario';
    $payload = [
        'from' => $from,
        'to' => [$toEmail],
        'subject' => 'Recuperação de senha - Clube dos Parceiros',
        'text' => "Olá {$nameSafe},\n\nRecebemos um pedido para redefinir sua senha.\nUse o link abaixo (válido por 60 minutos):\n{$resetUrl}\n\nSe você não solicitou, ignore este e-mail.\n",
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status >= 200 && $status < 300;
}

function isLocalEnvironment(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return $host === 'localhost'
        || str_starts_with($host, 'localhost:')
        || str_starts_with($host, '127.0.0.1')
        || str_starts_with($host, '[::1]');
}

function registerAttemptFailed(): void
{
    $now = time();
    $windowStart = $_SESSION['login_window_start'] ?? $now;
    $count = (int) ($_SESSION['login_fail_count'] ?? 0);

    if (($now - (int) $windowStart) > 300) {
        $_SESSION['login_window_start'] = $now;
        $_SESSION['login_fail_count'] = 1;
        return;
    }

    $_SESSION['login_fail_count'] = $count + 1;
}

function resetAttemptFailures(): void
{
    unset($_SESSION['login_window_start'], $_SESSION['login_fail_count']);
}

function isLoginTemporarilyBlocked(): bool
{
    $windowStart = (int) ($_SESSION['login_window_start'] ?? 0);
    $count = (int) ($_SESSION['login_fail_count'] ?? 0);
    if ($windowStart <= 0 || $count < 6) {
        return false;
    }
    return (time() - $windowStart) <= 300;
}

function registerForgotAttempt(): void
{
    $now = time();
    $windowStart = $_SESSION['forgot_window_start'] ?? $now;
    $count = (int) ($_SESSION['forgot_count'] ?? 0);

    if (($now - (int) $windowStart) > 900) {
        $_SESSION['forgot_window_start'] = $now;
        $_SESSION['forgot_count'] = 1;
        return;
    }

    $_SESSION['forgot_count'] = $count + 1;
}

function isForgotTemporarilyBlocked(): bool
{
    $windowStart = (int) ($_SESSION['forgot_window_start'] ?? 0);
    $count = (int) ($_SESSION['forgot_count'] ?? 0);
    if ($windowStart <= 0 || $count < 5) {
        return false;
    }
    return (time() - $windowStart) <= 900;
}

$requestedMode = strtolower(trim((string) ($_GET['mode'] ?? $_POST['mode'] ?? 'login')));
$allowedModes = ['login', 'register', 'forgot', 'reset'];
$mode = in_array($requestedMode, $allowedModes, true) ? $requestedMode : 'login';
$resetToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$nextPath = normalizeNextPath($_GET['next'] ?? $_POST['next'] ?? null, appPath('/access/area_profissional.php'));

if (isAuthenticated() && $mode !== 'forgot' && $mode !== 'reset') {
    header('Location: ' . $nextPath);
    exit;
}

$error = '';
$success = '';
$debugResetLink = '';
$formName = '';
$formEmail = '';
$formPhone = '';

try {
    if (!empty($cfg['app_auto_migrate'])) {
        $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, $options);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET $charset COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$db`");
        ensureUsersTable($pdo);
        ensurePasswordResetTable($pdo);
        ensureStripeTables($pdo);
    } else {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, $options);
        ensureStripeTables($pdo);
    }
} catch (PDOException $e) {
    $error = 'Não foi possível inicializar o login no banco de dados.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!verifyCsrfTokenOrFail($_POST['csrf_token'] ?? null)) {
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $postLimiter = rateLimitConsume('login_post', 30, 300);
        if (!$postLimiter['allowed']) {
            $error = 'Muitas tentativas em pouco tempo. Aguarde e tente novamente.';
        }
    }

    if ($error === '') {
        $postedMode = strtolower(trim((string) ($_POST['mode'] ?? 'login')));
        $mode = in_array($postedMode, $allowedModes, true) ? $postedMode : 'login';
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['senha'] ?? '');
        $formEmail = $email;

        if ($mode === 'register') {
            $registerLimiter = rateLimitConsume('register_post', 8, 3600, $email);
            if (!$registerLimiter['allowed']) {
                $error = 'Muitas tentativas de cadastro. Tente novamente mais tarde.';
            }
            $name = trim((string) ($_POST['nome'] ?? ''));
            $phoneRaw = trim((string) ($_POST['telefone'] ?? ''));
            $phone = preg_replace('/\D+/', '', $phoneRaw);
            $formName = $name;
            $formPhone = $phoneRaw;
            if ($error === '' && ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || strlen($phone) < 10 || strlen($phone) > 13)) {
                $error = 'Preencha nome, telefone, e-mail válido e senha com pelo menos 8 caracteres.';
            } elseif ($error === '' && !emailHasActiveSubscription($pdo, $email)) {
                $error = 'Para criar sua conta, primeiro faça a assinatura e use o mesmo e-mail do pagamento.';
            } elseif ($error === '') {
                try {
                    $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, telefone, senha_hash) VALUES (:nome, :email, :telefone, :senha_hash)');
                    $stmt->execute([
                        ':nome' => $name,
                        ':email' => $email,
                        ':telefone' => $phone,
                        ':senha_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ]);
                    $newUserId = (int) $pdo->lastInsertId();
                    if ($newUserId > 0) {
                        claimLatestSubscriptionForEmail($pdo, $newUserId, $email);
                    }
                    $success = 'Conta criada com sucesso. Faça login para continuar.';
                    $mode = 'login';
                } catch (PDOException $e) {
                    $error = 'Não foi possível criar a conta. Esse e-mail pode já estar em uso.';
                }
            }
        } elseif ($mode === 'forgot') {
            $forgotLimiter = rateLimitConsume('forgot_post', 6, 900, $email);
            if (!$forgotLimiter['allowed']) {
                $error = 'Muitas solicitações de recuperação. Aguarde alguns minutos e tente novamente.';
            } elseif (isForgotTemporarilyBlocked()) {
                $error = 'Muitas solicitações de recuperação. Aguarde alguns minutos e tente novamente.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Informe um e-mail válido.';
            } else {
                registerForgotAttempt();
                $lookupStmt = $pdo->prepare('SELECT id, nome, email FROM usuarios WHERE email = :email LIMIT 1');
                $lookupStmt->execute([':email' => $email]);
                $userRow = $lookupStmt->fetch();

                if ($userRow) {
                    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL')
                        ->execute([':user_id' => (int) $userRow['id']]);

                    $tokenPlain = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $tokenPlain);
                    $insertResetStmt = $pdo->prepare(
                        'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 60 MINUTE))'
                    );
                    $insertResetStmt->execute([
                        ':user_id' => (int) $userRow['id'],
                        ':token_hash' => $tokenHash,
                    ]);

                    $resetUrl = currentBaseUrl() . appPath('/access/login.php?mode=reset&token=' . urlencode($tokenPlain));
                    $sent = sendPasswordResetEmail((string) $userRow['email'], (string) $userRow['nome'], $resetUrl);
                    if (isLocalEnvironment()) {
                        $debugResetLink = $resetUrl;
                    }
                }
                $success = 'Se o e-mail existir, enviamos um link para redefinir a senha.';
            }
        } elseif ($mode === 'reset') {
            $resetToken = trim((string) ($_POST['token'] ?? ''));
            $newPassword = (string) ($_POST['nova_senha'] ?? '');
            $confirmPassword = (string) ($_POST['confirmar_senha'] ?? '');
            if (!preg_match('/^[a-f0-9]{64}$/', $resetToken)) {
                $error = 'Token de recuperação inválido.';
            } elseif (strlen($newPassword) < 8) {
                $error = 'A nova senha deve ter pelo menos 8 caracteres.';
            } elseif (!hash_equals($newPassword, $confirmPassword)) {
                $error = 'As senhas não conferem.';
            } else {
                $tokenHash = hash('sha256', $resetToken);
                $tokenStmt = $pdo->prepare(
                    'SELECT id, user_id
                     FROM password_resets
                     WHERE token_hash = :token_hash
                       AND used_at IS NULL
                       AND expires_at >= NOW()
                     ORDER BY id DESC
                     LIMIT 1'
                );
                $tokenStmt->execute([':token_hash' => $tokenHash]);
                $tokenRow = $tokenStmt->fetch();

                if (!$tokenRow) {
                    $error = 'Link de recuperação inválido ou expirado.';
                } else {
                    $updateUserStmt = $pdo->prepare('UPDATE usuarios SET senha_hash = :senha_hash WHERE id = :id LIMIT 1');
                    $updateUserStmt->execute([
                        ':senha_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                        ':id' => (int) $tokenRow['user_id'],
                    ]);

                    $consumeStmt = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id');
                    $consumeStmt->execute([':id' => (int) $tokenRow['id']]);

                    $success = 'Senha redefinida com sucesso. Faca login com a nova senha.';
                    $mode = 'login';
                    $resetToken = '';
                }
            }
        } else {
            $loginLimiter = rateLimitConsume('login_password', 12, 300, $email);
            if (!$loginLimiter['allowed']) {
                $error = 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.';
            } elseif (isLoginTemporarilyBlocked()) {
                $error = 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.';
            } elseif (envBool('ALLOW_ADMIN_LOGIN', false) && (isLocalEnvironment() || envBool('ALLOW_ADMIN_LOGIN_IN_PROD', false)) && $email === 'admin@admin.com' && $password === 'admin123') {
                try {
                    // Compatibilidade: se existir o admin antigo (email "admin"), migra para "admin@admin.com".
                    $adminLookup = $pdo->prepare("SELECT id, nome, email FROM usuarios WHERE email IN ('admin@admin.com', 'admin') ORDER BY id ASC LIMIT 1");
                    $adminLookup->execute();
                    $adminRow = $adminLookup->fetch() ?: null;

                    if (!$adminRow) {
                        $pdo->prepare("INSERT INTO usuarios (nome, email, telefone, senha_hash) VALUES ('ADMIN', 'admin@admin.com', NULL, :hash)")
                            ->execute([':hash' => password_hash('admin123', PASSWORD_DEFAULT)]);
                        $adminId = (int) $pdo->lastInsertId();
                        $adminName = 'ADMIN';
                    } else {
                        $adminId = (int) ($adminRow['id'] ?? 0);
                        $adminName = (string) ($adminRow['nome'] ?? 'ADMIN');
                        $adminEmail = strtolower((string) ($adminRow['email'] ?? ''));
                        if ($adminId > 0 && $adminEmail !== 'admin@admin.com') {
                            $pdo->prepare("UPDATE usuarios SET email = 'admin@admin.com' WHERE id = :id LIMIT 1")
                                ->execute([':id' => $adminId]);
                        }
                    }

                    if ($adminId > 0) {
                        $subLookup = $pdo->prepare("SELECT id FROM subscriptions WHERE user_id = :user_id ORDER BY id DESC LIMIT 1");
                        $subLookup->execute([':user_id' => $adminId]);
                        $subId = (int) ($subLookup->fetchColumn() ?: 0);
                        if ($subId > 0) {
                            $pdo->prepare("UPDATE subscriptions SET email = 'admin@admin.com', subscription_status = 'active', current_period_end = '2099-12-31 23:59:59' WHERE id = :id")
                                ->execute([':id' => $subId]);
                        } else {
                            $pdo->prepare("INSERT INTO subscriptions (user_id, email, subscription_status, current_period_end) VALUES (:user_id, 'admin@admin.com', 'active', '2099-12-31 23:59:59')")
                                ->execute([':user_id' => $adminId]);
                        }
                    }

                    loginUser($adminId, $adminName !== '' ? $adminName : 'ADMIN');
                    header('Location: ' . $nextPath);
                    exit;
                } catch (Throwable $e) {
                    $error = 'Não foi possível entrar como ADMIN.';
                }
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
                $error = 'Informe e-mail e senha válidos.';
            } else {
                $stmt = $pdo->prepare('SELECT id, nome, senha_hash FROM usuarios WHERE email = :email LIMIT 1');
                $stmt->execute([':email' => $email]);
                $userRow = $stmt->fetch();

                if (!$userRow || !password_verify($password, (string) $userRow['senha_hash'])) {
                    registerAttemptFailed();
                    $error = 'E-mail ou senha inválidos.';
                } else {
                    resetAttemptFailures();
                    $loggedUserId = (int) $userRow['id'];
                    claimLatestSubscriptionForEmail($pdo, $loggedUserId, $email);
                    loginUser($loggedUserId, (string) $userRow['nome']);
                    header('Location: ' . $nextPath);
                    exit;
                }
            }
        }
    }
}

if ($mode === 'reset' && $error === '' && $success === '') {
    if (!preg_match('/^[a-f0-9]{64}$/', $resetToken)) {
        $error = 'Link de recuperação inválido.';
    } else {
        $tokenHash = hash('sha256', $resetToken);
        $tokenStmt = $pdo->prepare(
            'SELECT id
             FROM password_resets
             WHERE token_hash = :token_hash
               AND used_at IS NULL
               AND expires_at >= NOW()
             ORDER BY id DESC
             LIMIT 1'
        );
        $tokenStmt->execute([':token_hash' => $tokenHash]);
        if (!$tokenStmt->fetch()) {
            $error = 'Link de recuperação inválido ou expirado.';
        }
    }
}

$csrf = ensureCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar - Clube dos Parceiros</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/theme.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; }
        .auth-card {
            animation: fadeUp .28s ease-out both;
        }
        .auth-field {
            transition: border-color .18s ease, box-shadow .18s ease;
        }
        .auth-field:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(147, 197, 253, .25);
            outline: none;
        }
        .auth-btn {
            transition: transform .18s ease, background .18s ease;
        }
        .auth-btn:hover { transform: translateY(-1px); }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (prefers-reduced-motion: reduce) {
            .auth-card { animation: none; }
            .auth-field,
            .auth-btn { transition: none; }
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4 py-10">
    <main class="auth-card w-full max-w-md bg-white border border-slate-200 rounded-2xl shadow-sm p-6">
        <?php
            $title = 'Entrar';
            $subtitle = 'Se você já assinou, entre com o mesmo e-mail do pagamento.';
            if ($mode === 'register') {
                $title = 'Criar conta';
                $subtitle = 'Primeiro faça a assinatura. Depois crie a conta com o mesmo e-mail do pagamento.';
            } elseif ($mode === 'forgot') {
                $title = 'Recuperar senha';
                $subtitle = 'Informe seu e-mail para receber o link de redefinicao.';
            } elseif ($mode === 'reset') {
                $title = 'Redefinir senha';
                $subtitle = 'Digite sua nova senha para concluir a recuperação.';
            }
        ?>
        <h1 class="text-2xl font-extrabold text-slate-900 mb-1"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-sm text-slate-500 mb-6"><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></p>

        <?php if ($error !== ''): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 text-red-700 text-sm px-3 py-2"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 text-sm px-3 py-2"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($debugResetLink !== ''): ?>
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 text-amber-800 text-sm px-3 py-2">
                Ambiente local detectado. Link de recuperação:
                <a class="underline break-all" href="<?php echo htmlspecialchars($debugResetLink, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($debugResetLink, ENT_QUOTES, 'UTF-8'); ?></a>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="mode" value="<?php echo htmlspecialchars($mode, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="next" value="<?php echo htmlspecialchars($nextPath, ENT_QUOTES, 'UTF-8'); ?>">

            <?php if ($mode === 'register'): ?>
                <div>
                    <label for="nome" class="block text-sm font-semibold text-slate-700 mb-1">Nome</label>
                    <input id="nome" name="nome" class="auth-field w-full rounded-xl border border-slate-300 px-4 py-3" value="<?php echo htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div>
                    <label for="telefone" class="block text-sm font-semibold text-slate-700 mb-1">Telefone</label>
                    <input id="telefone" name="telefone" class="auth-field w-full rounded-xl border border-slate-300 px-4 py-3" placeholder="Ex: 11999998888" value="<?php echo htmlspecialchars($formPhone, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
            <?php endif; ?>

            <?php if ($mode !== 'reset'): ?>
                <?php $emailInputType = 'email'; ?>
                <div>
                    <label for="email" class="block text-sm font-semibold text-slate-700 mb-1">E-mail</label>
                    <input id="email" name="email" type="<?php echo htmlspecialchars($emailInputType, ENT_QUOTES, 'UTF-8'); ?>" class="auth-field w-full rounded-xl border border-slate-300 px-4 py-3" value="<?php echo htmlspecialchars($formEmail, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
            <?php endif; ?>

            <?php if ($mode === 'login' || $mode === 'register'): ?>
                <div>
                    <label for="senha" class="block text-sm font-semibold text-slate-700 mb-1">Senha</label>
                    <input id="senha" name="senha" type="password" class="auth-field w-full rounded-xl border border-slate-300 px-4 py-3" minlength="8" required>
                </div>
            <?php endif; ?>

            <?php if ($mode === 'reset'): ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($resetToken, ENT_QUOTES, 'UTF-8'); ?>">
                <div>
                    <label for="nova_senha" class="block text-sm font-semibold text-slate-700 mb-1">Nova senha</label>
                    <input id="nova_senha" name="nova_senha" type="password" class="auth-field w-full rounded-xl border border-slate-300 px-4 py-3" minlength="8" required>
                </div>
                <div>
                    <label for="confirmar_senha" class="block text-sm font-semibold text-slate-700 mb-1">Confirmar nova senha</label>
                    <input id="confirmar_senha" name="confirmar_senha" type="password" class="auth-field w-full rounded-xl border border-slate-300 px-4 py-3" minlength="8" required>
                </div>
            <?php endif; ?>

            <?php
                $submitLabel = 'Entrar';
                if ($mode === 'register') {
                    $submitLabel = 'Criar conta';
                } elseif ($mode === 'forgot') {
                    $submitLabel = 'Enviar link de recuperação';
                } elseif ($mode === 'reset') {
                    $submitLabel = 'Salvar nova senha';
                }
            ?>

            <button type="submit" class="auth-btn w-full rounded-xl bg-blue-700 hover:bg-blue-800 text-white font-bold py-3">
                <?php echo htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8'); ?>
            </button>
        </form>

        <div class="mt-5 text-sm text-slate-600">
            <?php if ($mode === 'register'): ?>
                Já tem conta? <a href="<?php echo htmlspecialchars(appPath('/access/login.php?mode=login&next=' . rawurlencode($nextPath)), ENT_QUOTES, 'UTF-8'); ?>" class="text-blue-700 font-semibold hover:underline">Entrar</a>
            <?php elseif ($mode === 'login'): ?>
                Ainda não assinou? <a href="<?php echo htmlspecialchars(appPath('/access/checkout.html'), ENT_QUOTES, 'UTF-8'); ?>" class="text-blue-700 font-semibold hover:underline">Assinar e criar conta</a>
                <span class="mx-2 text-slate-300">|</span>
                <a href="<?php echo htmlspecialchars(appPath('/access/login.php?mode=forgot'), ENT_QUOTES, 'UTF-8'); ?>" class="text-blue-700 font-semibold hover:underline">Esqueci minha senha</a>
            <?php elseif ($mode === 'forgot'): ?>
                Lembrou a senha? <a href="<?php echo htmlspecialchars(appPath('/access/login.php?mode=login&next=' . rawurlencode($nextPath)), ENT_QUOTES, 'UTF-8'); ?>" class="text-blue-700 font-semibold hover:underline">Entrar</a>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars(appPath('/access/login.php?mode=login&next=' . rawurlencode($nextPath)), ENT_QUOTES, 'UTF-8'); ?>" class="text-blue-700 font-semibold hover:underline">Voltar para entrar</a>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>

