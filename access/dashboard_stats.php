<?php
require_once __DIR__ . '/../secure/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function jsonOut(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
} catch (Throwable $e) {
    jsonOut(['ok' => false, 'message' => 'Não foi possível conectar ao banco de dados.'], 200);
}

function safeCount(PDO $pdo, string $table): ?int
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        $exists = $stmt !== false && $stmt->fetchColumn();
        if (!$exists) {
            return null;
        }
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
        return $countStmt ? (int) $countStmt->fetchColumn() : 0;
    } catch (Throwable $e) {
        return null;
    }
}

$users = safeCount($pdo, 'usuarios');
$professionals = safeCount($pdo, 'profissionais');

jsonOut([
    'ok' => true,
    'users' => $users,
    'professionals' => $professionals,
    'updated_at' => gmdate('c'),
]);

