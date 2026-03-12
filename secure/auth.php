<?php
require_once __DIR__ . '/config.php';

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (function_exists('ini_set')) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => function_exists('isHttpsRequest')
            ? isHttpsRequest()
            : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
    enforceSessionSecurity();
}

function ensureCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfTokenOrFail(?string $token): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || !is_string($sessionToken) || $sessionToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $token);
}

function isAuthenticated(): bool
{
    return isset($_SESSION['auth_user_id']) && is_int($_SESSION['auth_user_id']) && $_SESSION['auth_user_id'] > 0;
}

function currentUserId(): ?int
{
    return isAuthenticated() ? $_SESSION['auth_user_id'] : null;
}

function currentUserName(): string
{
    $name = $_SESSION['auth_user_name'] ?? '';
    return is_string($name) ? $name : '';
}

function currentUserEmail(): string
{
    $email = $_SESSION['auth_user_email'] ?? '';
    return is_string($email) ? $email : '';
}

function isAdminEmail(string $email): bool
{
    if (!envBool('ALLOW_ADMIN_LOGIN', false)) {
        return false;
    }

    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    $raw = (string) envValue('ADMIN_EMAILS', '');
    if (trim($raw) === '') {
        return false;
    }

    $parts = preg_split('/[,\s;]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts) || $parts === []) {
        return false;
    }

    return in_array($email, $parts, true);
}

function currentUserIsAdmin(): bool
{
    $flag = $_SESSION['auth_is_admin'] ?? null;
    if (is_bool($flag)) {
        return $flag;
    }

    return isAdminEmail(currentUserEmail());
}

function loginUser(int $userId, string $userName, string $userEmail = ''): void
{
    session_regenerate_id(true);
    $_SESSION['auth_user_id'] = $userId;
    $_SESSION['auth_user_name'] = $userName;
    $_SESSION['auth_user_email'] = $userEmail;
    $_SESSION['auth_is_admin'] = isAdminEmail($userEmail);
    $_SESSION['__last_activity'] = time();
    $_SESSION['__last_regenerated'] = time();
}

function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

function normalizeNextPath(?string $next, string $defaultPath): string
{
    $next = trim((string) $next);
    if ($next === '') {
        return $defaultPath;
    }

    if ($next[0] !== '/') {
        return $defaultPath;
    }

    if (str_starts_with($next, '//') || str_contains($next, '\\') || str_contains($next, '://')) {
        return $defaultPath;
    }

    if (preg_match('/[\r\n]/', $next)) {
        return $defaultPath;
    }

    return $next;
}

function enforceSessionSecurity(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $cfg = function_exists('appConfig') ? appConfig() : [];
    $idleTimeout = (int) ($cfg['session_idle_timeout'] ?? 1800);
    $regenInterval = (int) ($cfg['session_regen_interval'] ?? 900);
    $now = time();

    $lastActivity = (int) ($_SESSION['__last_activity'] ?? 0);
    if (isAuthenticated() && $lastActivity > 0 && ($now - $lastActivity) > $idleTimeout) {
        logoutUser();
        startSecureSession();
        return;
    }

    $_SESSION['__last_activity'] = $now;

    $lastRegenerated = (int) ($_SESSION['__last_regenerated'] ?? 0);
    if ($lastRegenerated <= 0) {
        $_SESSION['__last_regenerated'] = $now;
        return;
    }
    if (($now - $lastRegenerated) >= $regenInterval) {
        session_regenerate_id(true);
        $_SESSION['__last_regenerated'] = $now;
    }
}

function clientIpAddress(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    $trustProxy = function_exists('envBool') ? envBool('TRUST_PROXY', false) : false;
    if (!$trustProxy) {
        return $remote;
    }

    $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded === '') {
        return $remote;
    }

    $parts = explode(',', $forwarded);
    $candidate = trim((string) ($parts[0] ?? ''));
    return $candidate !== '' ? $candidate : $remote;
}

function rateLimitConsume(string $scope, int $limit, int $windowSeconds, string $identity = ''): array
{
    $limit = max(1, $limit);
    $windowSeconds = max(1, $windowSeconds);
    $dir = dirname(__DIR__) . '/storage/rate_limits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $keySeed = strtolower($scope) . '|' . strtolower(trim($identity)) . '|' . clientIpAddress();
    $key = hash('sha256', $keySeed);
    $path = $dir . '/' . $key . '.json';
    $now = time();
    $bucket = ['window_start' => $now, 'count' => 0];

    if (is_file($path)) {
        $raw = file_get_contents($path);
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $bucket['window_start'] = (int) ($decoded['window_start'] ?? $now);
            $bucket['count'] = (int) ($decoded['count'] ?? 0);
        }
    }

    if (($now - (int) $bucket['window_start']) >= $windowSeconds) {
        $bucket['window_start'] = $now;
        $bucket['count'] = 0;
    }

    $bucket['count']++;
    file_put_contents($path, json_encode($bucket, JSON_UNESCAPED_SLASHES), LOCK_EX);

    $allowed = $bucket['count'] <= $limit;
    $retryAfter = $allowed ? 0 : max(1, $windowSeconds - ($now - (int) $bucket['window_start']));
    $remaining = max(0, $limit - $bucket['count']);

    return [
        'allowed' => $allowed,
        'retry_after' => $retryAfter,
        'remaining' => $remaining,
    ];
}
