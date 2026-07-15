<?php

function envValue(string $key, ?string $default = null): ?string
{
    static $loadedDotenv = false;
    if (!$loadedDotenv) {
        $loadedDotenv = true;
        $envPath = dirname(__DIR__) . '/.env';
        if (is_file($envPath) && is_readable($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    $k = trim($k);
                    $v = trim($v);
                    if ($k === '' || getenv($k) !== false) {
                        continue;
                    }
                    if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                        $v = substr($v, 1, -1);
                    }
                    putenv($k . '=' . $v);
                    $_ENV[$k] = $v;
                    $_SERVER[$k] = $v;
                }
            }
        }
    }

    $value = getenv($key);
    return $value === false ? $default : $value;
}

function envBool(string $key, bool $default = false): bool
{
    $raw = envValue($key);
    if ($raw === null) {
        return $default;
    }
    $normalized = strtolower(trim($raw));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function appConfig(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $basePath = envValue('APP_BASE_PATH');
    $basePath = is_string($basePath) ? trim($basePath) : '';
    if ($basePath === '') {
        $basePath = detectAppBasePath();
    }
    $basePath = $basePath === '/' ? '' : rtrim($basePath, '/');

    $config = [
        'db_host' => (string) envValue('DB_HOST', 'localhost'),
        'db_name' => (string) envValue('DB_NAME', 'servicos_db'),
        'db_user' => (string) envValue('DB_USER', 'root'),
        'db_pass' => (string) envValue('DB_PASS', ''),
        'db_charset' => (string) envValue('DB_CHARSET', 'utf8mb4'),
        'app_base_path' => $basePath,
        'app_url' => rtrim((string) envValue('APP_URL', ''), '/'),
        'app_auto_migrate' => envBool('APP_AUTO_MIGRATE', false),
        'mail_provider' => strtolower((string) envValue('MAIL_PROVIDER', 'resend')),
        'mail_from' => (string) envValue('MAIL_FROM', ''),
        'resend_api_key' => (string) envValue('RESEND_API_KEY', ''),
        'session_idle_timeout' => max(300, (int) envValue('SESSION_IDLE_TIMEOUT', '1800')),
        'session_regen_interval' => max(120, (int) envValue('SESSION_REGEN_INTERVAL', '900')),
    ];

    return $config;
}

function detectAppBasePath(): string
{
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($scriptName === '') {
        return '';
    }

    $scriptName = '/' . ltrim(str_replace('\\', '/', $scriptName), '/');

    foreach (['/access/', '/secure/'] as $marker) {
        $pos = strpos($scriptName, $marker);
        if ($pos !== false && $pos > 0) {
            return substr($scriptName, 0, $pos);
        }
    }

    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($dir === '' || $dir === '.') {
        return '';
    }

    foreach (['/access', '/secure'] as $suffix) {
        if (str_ends_with($dir, $suffix)) {
            $dir = substr($dir, 0, -strlen($suffix));
            $dir = rtrim($dir, '/');
            break;
        }
    }

    return $dir === '/' ? '' : $dir;
}

function isLocalEnvironment(): bool
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    if ($host !== '') {
        $host = explode(':', $host, 2)[0];
    }

    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return true;
    }

    $appUrl = strtolower(trim((string) envValue('APP_URL', '')));
    if ($appUrl !== '' && (str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1'))) {
        return true;
    }

    return false;
}

function applySecurityHeaders(): void
{
    static $applied = false;
    if ($applied || headers_sent()) {
        return;
    }
    $applied = true;

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    if (isHttpsRequest()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    $csp = implode('; ', [
        "default-src 'self'",
        "base-uri 'self'",
        "frame-ancestors 'none'",
        "object-src 'none'",
        "form-action 'self'",
        "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://unpkg.com https://cdnjs.cloudflare.com",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://unpkg.com",
        "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",
        "img-src 'self' data: blob: https:",
        "connect-src 'self' https://api.resend.com",
    ]);
    header('Content-Security-Policy: ' . $csp);
}

applySecurityHeaders();

function isHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $trustProxy = envBool('TRUST_PROXY', false);
    if (!$trustProxy) {
        return false;
    }

    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto !== '') {
        $first = strtolower(trim((string) (explode(',', $forwardedProto, 2)[0] ?? '')));
        if ($first === 'https') {
            return true;
        }
    }

    $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
    if ($forwardedSsl === 'on' || $forwardedSsl === '1') {
        return true;
    }

    return false;
}

function appPath(string $path = '/'): string
{
    $cfg = appConfig();
    $path = '/' . ltrim($path, '/');
    return ($cfg['app_base_path'] ?? '') . $path;
}

function appBaseUrl(): string
{
    $cfg = appConfig();
    $trustProxy = envBool('TRUST_PROXY', false);
    $scheme = isHttpsRequest() ? 'https' : 'http';

    $hostHeader = '';
    if ($trustProxy) {
        $forwardedHost = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        if ($forwardedHost !== '') {
            $hostHeader = trim((string) (explode(',', $forwardedHost, 2)[0] ?? ''));
        }
    }
    if ($hostHeader === '') {
        $hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '');
    }
    $hostHeader = trim($hostHeader) !== '' ? trim($hostHeader) : 'localhost';

    // Em ambiente local (localhost/127.0.0.1), priorize o host/porta atuais
    // para evitar links/callbacks com porta errada quando APP_URL aponta para producao.
    if (isLocalEnvironment()) {
        return $scheme . '://' . $hostHeader;
    }

    if ($cfg['app_url'] !== '') {
        return $cfg['app_url'];
    }

    return $scheme . '://' . $hostHeader;
}
