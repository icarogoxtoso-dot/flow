<?php
require_once __DIR__ . '/../secure/auth.php';
require_once __DIR__ . '/../secure/config.php';

startSecureSession();
header('Content-Type: application/json; charset=utf-8');

function jsonFail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonFail('Método não permitido.', 405);
}

$saveProfileUrl = appPath('/secure/save_profile.php');

// Cadastro gratuito:
// - Sem Stripe/checkout
// - Sem exigir login neste endpoint
// - Se não estiver logado, manda direto para registro com retorno para o formulário de perfil
if (!isAuthenticated()) {
    $registerUrl = appPath('/access/login.php?mode=register&next=' . rawurlencode($saveProfileUrl));
    echo json_encode(['ok' => true, 'url' => $registerUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = (int) (currentUserId() ?? 0);
if ($userId <= 0) {
    jsonFail('Sessão inválida.', 401);
}

echo json_encode(['ok' => true, 'url' => $saveProfileUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

