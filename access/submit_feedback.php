<?php
require_once __DIR__ . '/../secure/auth.php';
startSecureSession();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../secure/config.php';

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

function jsonError(string $message, int $status = 400): never
{
    http_response_code($status);
    if ($status === 429) {
        header('Retry-After: 60');
    }
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método não permitido.', 405);
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
$payload = [];
if (str_contains($contentType, 'application/json')) {
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode((string) $rawBody, true);
    if (!is_array($decoded)) {
        jsonError('Corpo da requisição inválido.');
    }
    $payload = $decoded;
} else {
    $payload = $_POST;
}

$csrf = (string) ($payload['csrf_token'] ?? '');
$sessionCsrf = (string) ($_SESSION['feedback_csrf'] ?? '');
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    jsonError('Falha de segurança (CSRF). Atualize a página e tente novamente.', 403);
}

$publicId = strtolower(trim((string) ($payload['public_id'] ?? '')));
$rating = (int) ($payload['rating'] ?? 0);
$comment = trim((string) ($payload['comment'] ?? ''));
$clientName = trim((string) ($payload['client_name'] ?? ''));
$imagePath = null;

$feedbackLimiter = rateLimitConsume('feedback_submit', 10, 300, $publicId);
if (!$feedbackLimiter['allowed']) {
    jsonError('Muitas tentativas de envio de avaliação. Aguarde um minuto e tente novamente.', 429);
}

if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
    jsonError('Profissional inválido.');
}
if ($rating < 1 || $rating > 5) {
    jsonError('Nota invalida.');
}
if (mb_strlen($comment) < 10 || mb_strlen($comment) > 500) {
    jsonError('Comentario deve ter entre 10 e 500 caracteres.');
}
if (mb_strlen($clientName) > 80) {
    jsonError('Nome muito longo.');
}

$now = time();
$lastFeedbackAt = (int) ($_SESSION['feedback_last_submit'] ?? 0);
if ($lastFeedbackAt > 0 && ($now - $lastFeedbackAt) < 30) {
    jsonError('Aguarde alguns segundos antes de enviar outro feedback.', 429);
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, $options);
} catch (PDOException $e) {
    jsonError('Não foi possível conectar ao banco no momento.', 500);
}

if (isset($_FILES['feedback_image']) && is_array($_FILES['feedback_image'])) {
    $file = $_FILES['feedback_image'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            jsonError('Não foi possível enviar a imagem do feedback.');
        }
        if ((int) ($file['size'] ?? 0) > 15 * 1024 * 1024) {
            jsonError('A imagem do feedback deve ter no máximo 15MB.');
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            jsonError('Arquivo de imagem inválido.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpPath);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            jsonError('Formato de imagem não suportado. Use JPG, PNG ou WEBP.');
        }
        $uploadDir = __DIR__ . '/../uploads/feedbacks';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            jsonError('Não foi possível preparar a pasta de feedback.');
        }
        $filename = 'feedback_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
        $target = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($tmpPath, $target)) {
            jsonError('Não foi possível salvar a imagem enviada.');
        }
        $imagePath = appPath('/uploads/feedbacks/' . $filename);
    }
}

try {
    $profStmt = $pdo->prepare('SELECT id FROM profissionais WHERE public_id = :public_id LIMIT 1');
    $profStmt->execute([':public_id' => $publicId]);
    $prof = $profStmt->fetch();
    if (!$prof) {
        jsonError('Profissional não encontrado.', 404);
    }

    $profId = (int) $prof['id'];
    $fingerprint = hash('sha256', session_id() . '|' . ((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));

    $dupStmt = $pdo->prepare(
        'SELECT id
         FROM feedbacks
         WHERE profissional_id = :profissional_id
           AND fingerprint = :fingerprint
           AND created_at >= (NOW() - INTERVAL 1 DAY)
         LIMIT 1'
    );
    $dupStmt->execute([
        ':profissional_id' => $profId,
        ':fingerprint' => $fingerprint,
    ]);
    if ($dupStmt->fetch()) {
        jsonError('Você já enviou feedback recente para este profissional. Tente novamente depois.', 429);
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO feedbacks (profissional_id, client_name, rating, comment, image_path, fingerprint)
         VALUES (:profissional_id, :client_name, :rating, :comment, :image_path, :fingerprint)'
    );
    $insertStmt->execute([
        ':profissional_id' => $profId,
        ':client_name' => ($clientName !== '' ? $clientName : null),
        ':rating' => $rating,
        ':comment' => $comment,
        ':image_path' => $imagePath,
        ':fingerprint' => $fingerprint,
    ]);

    $aggregateStmt = $pdo->prepare(
        'SELECT ROUND(AVG(rating), 1) AS avg_rating, COUNT(*) AS total_feedbacks
         FROM feedbacks
         WHERE profissional_id = :profissional_id'
    );
    $aggregateStmt->execute([':profissional_id' => $profId]);
    $agg = $aggregateStmt->fetch() ?: ['avg_rating' => 0, 'total_feedbacks' => 0];

    $avgRating = (float) ($agg['avg_rating'] ?? 0);
    $totalFeedbacks = (int) ($agg['total_feedbacks'] ?? 0);

    $updateProfStmt = $pdo->prepare('UPDATE profissionais SET nota = :nota, total_avaliacoes = :total_avaliacoes WHERE id = :id');
    $updateProfStmt->execute([
        ':nota' => $avgRating,
        ':total_avaliacoes' => $totalFeedbacks,
        ':id' => $profId,
    ]);

    $_SESSION['feedback_last_submit'] = $now;

    echo json_encode([
        'ok' => true,
        'avg_rating' => $avgRating,
        'total_feedbacks' => $totalFeedbacks,
        'message' => 'Feedback enviado.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    jsonError('Não foi possível salvar o feedback.', 500);
}

