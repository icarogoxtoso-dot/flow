<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/stripe_db.php';
startSecureSession();

$cfg = appConfig();
$nextAfterLogin = appPath('/secure/save_profile.php');
if (!isAuthenticated()) {
    header('Location: ' . appPath('/access/login.php?mode=login&next=' . rawurlencode($nextAfterLogin))); 
    exit;
}

$authUserId = currentUserId();
$authUserName = currentUserName();

$host = $cfg['db_host'];
$db = $cfg['db_name'];
$user = $cfg['db_user'];
$pass = $cfg['db_pass'];
$charset = $cfg['db_charset'];
$schemaFile = __DIR__ . '/../scripts/migrations/001_init.sql';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

function loadSchemaStatements(string $filePath): array
{
    if (!is_file($filePath)) {
        return [];
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $allowedStarts = ['CREATE TABLE', 'ALTER TABLE'];
    $statements = [];
    $buffer = '';

    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '--') || strtoupper($trim) === 'SQL PARA CRIAR O BANCO E A TABELA:') {
            continue;
        }

        $upper = strtoupper($trim);

        if ($buffer === '') {
            $isSqlStart = false;
            foreach ($allowedStarts as $prefix) {
                if (str_starts_with($upper, $prefix)) {
                    $isSqlStart = true;
                    break;
                }
            }

            if (!$isSqlStart) {
                continue;
            }
        }

        $buffer .= ($buffer === '' ? '' : "\n") . $trim;

        if (str_ends_with($trim, ';')) {
            $statements[] = $buffer;
            $buffer = '';
        }
    }

    if ($buffer !== '') {
        $statements[] = $buffer . ';';
    }

    return $statements;
}

function ensureProfessionalTableColumns(PDO $pdo): void
{
    $requiredColumns = [
        'nome' => "VARCHAR(100) NULL",
        'online' => "TINYINT(1) DEFAULT 1",
        'bairro' => "VARCHAR(80) NULL",
        'cidade' => "VARCHAR(80) NULL",
        'tags' => "VARCHAR(255) NULL",
        'descricao' => "TEXT NULL",
        'fotos_trabalhos' => "TEXT NULL",
        'desde' => "YEAR NULL",
        'nota' => "DECIMAL(2,1) NOT NULL DEFAULT 0.0",
        'whatsapp' => "VARCHAR(20) NULL",
        'instagram' => "VARCHAR(120) NULL",
        'site_url' => "VARCHAR(255) NULL",
        'facebook' => "VARCHAR(255) NULL",
        'youtube' => "VARCHAR(255) NULL",
        'foto_perfil' => "VARCHAR(255) NULL",
        'public_id' => "CHAR(32) NULL",
        'user_id' => "INT NULL",
    ];

    foreach ($requiredColumns as $column => $definition) {
        $columnStmt = $pdo->query("SHOW COLUMNS FROM profissionais LIKE " . $pdo->quote($column));
        $columnExists = $columnStmt !== false && $columnStmt->fetch();
        if (!$columnExists) {
            $pdo->exec("ALTER TABLE profissionais ADD COLUMN `$column` $definition");
        }
    }

    $pdo->exec("ALTER TABLE profissionais MODIFY COLUMN nota DECIMAL(2,1) NOT NULL DEFAULT 0.0");
}

function ensureProfessionalOwnershipConstraints(PDO $pdo): void
{
    $userIdxStmt = $pdo->query("SHOW INDEX FROM profissionais WHERE Key_name = 'ux_profissionais_user_id'");
    $hasUserIndex = $userIdxStmt !== false && $userIdxStmt->fetch();
    if (!$hasUserIndex) {
        $pdo->exec("ALTER TABLE profissionais ADD UNIQUE KEY `ux_profissionais_user_id` (`user_id`)");
    }

    $publicIdxStmt = $pdo->query("SHOW INDEX FROM profissionais WHERE Key_name = 'ux_profissionais_public_id'");
    $hasPublicIndex = $publicIdxStmt !== false && $publicIdxStmt->fetch();
    if (!$hasPublicIndex) {
        $pdo->exec("ALTER TABLE profissionais ADD UNIQUE KEY `ux_profissionais_public_id` (`public_id`)");
    }
}

function generateUniquePublicId(PDO $pdo): string
{
    $existsStmt = $pdo->prepare("SELECT 1 FROM profissionais WHERE public_id = :public_id LIMIT 1");
    do {
        $candidate = bin2hex(random_bytes(16));
        $existsStmt->execute([':public_id' => $candidate]);
        $alreadyExists = (bool) $existsStmt->fetchColumn();
    } while ($alreadyExists);

    return $candidate;
}

function ensureProfessionalPublicIds(PDO $pdo): void
{
    $missingStmt = $pdo->query("SELECT id FROM profissionais WHERE public_id IS NULL OR public_id = ''");
    if ($missingStmt === false) {
        return;
    }

    $rows = $missingStmt->fetchAll();
    if (!$rows) {
        return;
    }

    $updateStmt = $pdo->prepare("UPDATE profissionais SET public_id = :public_id WHERE id = :id");
    foreach ($rows as $row) {
        $updateStmt->execute([
            ':public_id' => generateUniquePublicId($pdo),
            ':id' => (int) $row['id'],
        ]);
    }
}

function getUploadedProfilePhotoPath(array $file, string &$uploadError): ?string
{
    $uploadError = '';

    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadError = 'Não foi possível enviar a foto de perfil.';
        return null;
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        $uploadError = 'A foto deve ter no máximo 5MB.';
        return null;
    }

    $tmpPath = $file['tmp_name'] ?? '';
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $uploadError = 'Arquivo de foto inválido.';
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpPath);
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedTypes[$mimeType])) {
        $uploadError = 'Formato de imagem não suportado. Use JPG, PNG ou WEBP.';
        return null;
    }

    $uploadDir = __DIR__ . '/../uploads/profiles';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        $uploadError = 'Não foi possível preparar a pasta de upload.';
        return null;
    }

    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $suffix = uniqid('', true);
    }

    $filename = 'profile_' . $suffix . '.' . $allowedTypes[$mimeType];
    $targetPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        $uploadError = 'Não foi possível salvar a foto enviada.';
        return null;
    }

    return appPath('/uploads/profiles/' . $filename);
}

function saveUploadedWorkPhotos(array $files, string &$uploadError): array
{
    $uploadError = '';

    if (!isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $uploadDir = __DIR__ . '/../uploads/works';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        $uploadError = 'Não foi possível preparar a pasta das fotos de trabalhos.';
        return [];
    }

    $validIndexes = [];
    foreach ($files['error'] as $idx => $err) {
        if ($err !== UPLOAD_ERR_NO_FILE) {
            $validIndexes[] = $idx;
        }
    }

    if (count($validIndexes) > 6) {
        $uploadError = 'Você pode enviar no máximo 6 fotos de trabalhos.';
        return [];
    }

    $savedPaths = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    foreach ($validIndexes as $idx) {
        $err = $files['error'][$idx];
        if ($err !== UPLOAD_ERR_OK) {
            $uploadError = 'Não foi possível enviar uma das fotos de trabalhos.';
            return [];
        }

        $size = (int) ($files['size'][$idx] ?? 0);
        if ($size > 5 * 1024 * 1024) {
            $uploadError = 'Cada foto de trabalho deve ter no máximo 5MB.';
            return [];
        }

        $tmpPath = (string) ($files['tmp_name'][$idx] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            $uploadError = 'Arquivo de foto de trabalho inválido.';
            return [];
        }

        $mimeType = (string) $finfo->file($tmpPath);
        if (!isset($allowedTypes[$mimeType])) {
            $uploadError = 'Formato de foto de trabalho não suportado. Use JPG, PNG ou WEBP.';
            return [];
        }

        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            $suffix = uniqid('', true);
        }

        $filename = 'work_' . $suffix . '.' . $allowedTypes[$mimeType];
        $targetPath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($tmpPath, $targetPath)) {
            $uploadError = 'Não foi possível salvar uma das fotos de trabalhos.';
            return [];
        }

        $savedPaths[] = appPath('/uploads/works/' . $filename);
    }

    return $savedPaths;
}

function normalizeOptionalUrl(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $value)) {
        $value = 'https://' . $value;
    }

    if (filter_var($value, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    return $value;
}

function normalizeOptionalSocialUrl(string $value, string $platform): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $value)) {
        return normalizeOptionalUrl($value);
    }

    $handle = trim($value);
    if ($platform === 'youtube') {
        if (str_starts_with($handle, '/')) {
            $handle = ltrim($handle, '/');
            return normalizeOptionalUrl('https://youtube.com/' . $handle);
        }
        $handle = ltrim($handle, '@');
        $handle = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $handle);
        if ($handle === '') {
            return '';
        }
        return normalizeOptionalUrl('https://youtube.com/@' . $handle);
    }

    $handle = ltrim($handle, '@');
    $handle = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $handle);
    if ($handle === '') {
        return '';
    }
    return normalizeOptionalUrl('https://' . $platform . '.com/' . $handle);
}

$error = '';
$success = '';
$existingProfile = null;
$existingWorkPhotos = [];
$existingProfilePhoto = null;
$formValues = [
    'nome' => '',
    'cidade' => '',
    'bairro' => '',
    'tags' => '',
    'descricao' => '',
    'desde' => '',
    'whatsapp' => '',
    'instagram' => '',
    'site_url' => '',
    'facebook' => '',
    'youtube' => '',
    'online' => true,
];

try {
    if (!empty($cfg['app_auto_migrate'])) {
        $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, $options);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET $charset COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$db`");

        $tableExistsStmt = $pdo->query("SHOW TABLES LIKE 'profissionais'");
        $tableExists = $tableExistsStmt !== false && $tableExistsStmt->fetchColumn();

        if (!$tableExists) {
            $schemaStatements = loadSchemaStatements($schemaFile);
            foreach ($schemaStatements as $sql) {
                $pdo->exec($sql);
            }
        }

        ensureProfessionalTableColumns($pdo);
        ensureProfessionalOwnershipConstraints($pdo);
        ensureProfessionalPublicIds($pdo);
    } else {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, $options);
    }

    $requireSubscription = envBool('REQUIRE_SUBSCRIPTION', true);
    if ($requireSubscription && !currentUserIsAdmin()) {
        ensureStripeTablesOrFail($pdo);
        if (!userCanCreateProfile($pdo, (int) $authUserId)) {
            header('Location: ' . appPath('/access/checkout.html'));
            exit;
        }
    }

    $ownerStmt = $pdo->prepare('SELECT * FROM profissionais WHERE user_id = :user_id LIMIT 1');
    $ownerStmt->execute([':user_id' => $authUserId]);
    $existingProfile = $ownerStmt->fetch() ?: null;

    if ($existingProfile) {
        $formValues['nome'] = (string) ($existingProfile['nome'] ?? '');
        $formValues['cidade'] = (string) ($existingProfile['cidade'] ?? '');
        $formValues['bairro'] = (string) ($existingProfile['bairro'] ?? '');
        $formValues['tags'] = (string) ($existingProfile['tags'] ?? '');
        $formValues['descricao'] = (string) ($existingProfile['descricao'] ?? '');
        $formValues['desde'] = (string) ($existingProfile['desde'] ?? '');
        $formValues['whatsapp'] = (string) ($existingProfile['whatsapp'] ?? '');
        $formValues['instagram'] = (string) ($existingProfile['instagram'] ?? '');
        $formValues['site_url'] = (string) ($existingProfile['site_url'] ?? '');
        $formValues['facebook'] = (string) ($existingProfile['facebook'] ?? '');
        $formValues['youtube'] = (string) ($existingProfile['youtube'] ?? '');
        $formValues['online'] = ((int) ($existingProfile['online'] ?? 1)) === 1;
        $existingProfilePhoto = isset($existingProfile['foto_perfil']) ? trim((string) $existingProfile['foto_perfil']) : null;

        $photosRaw = (string) ($existingProfile['fotos_trabalhos'] ?? '');
        $decoded = json_decode($photosRaw, true);
        if (is_array($decoded)) {
            $existingWorkPhotos = array_values(array_filter(array_map(static function ($item) {
                return is_string($item) ? trim($item) : '';
            }, $decoded)));
        }
    }
} catch (Throwable $e) {
    if ($e instanceof PDOException) {
        $error = 'Erro ao conectar ao banco: ' . $e->getMessage();
    } else {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!verifyCsrfTokenOrFail($_POST['csrf_token'] ?? null)) {
        $error = 'Sessão expirada. Recarregue a página e tente novamente.';
    }

    $formValues['nome'] = trim((string) ($_POST['nome'] ?? ''));
    $formValues['cidade'] = trim((string) ($_POST['cidade'] ?? ''));
    $formValues['bairro'] = trim((string) ($_POST['bairro'] ?? ''));
    $formValues['tags'] = trim((string) ($_POST['tags'] ?? ''));
    $formValues['descricao'] = trim((string) ($_POST['descricao'] ?? ''));
    $formValues['desde'] = trim((string) ($_POST['desde'] ?? ''));
    $formValues['whatsapp'] = trim((string) ($_POST['whatsapp'] ?? ''));
    $formValues['instagram'] = trim((string) ($_POST['instagram'] ?? ''));
    $formValues['site_url'] = trim((string) ($_POST['site_url'] ?? ''));
    $formValues['facebook'] = trim((string) ($_POST['facebook'] ?? ''));
    $formValues['youtube'] = trim((string) ($_POST['youtube'] ?? ''));
    $formValues['online'] = isset($_POST['online']);

    $nome = $formValues['nome'];
    $cidade = $formValues['cidade'];
    $bairro = $formValues['bairro'];
    $tags = trim($formValues['tags']);
    $descricao = trim($formValues['descricao']);
    $whatsapp = preg_replace('/\D+/', '', $formValues['whatsapp']);
    $desde = trim($formValues['desde']);
    $instagram = normalizeOptionalSocialUrl($formValues['instagram'], 'instagram');
    $siteUrl = normalizeOptionalUrl($formValues['site_url']);
    $facebook = normalizeOptionalSocialUrl($formValues['facebook'], 'facebook');
    $youtube = normalizeOptionalSocialUrl($formValues['youtube'], 'youtube');

    if ($nome === '') {
        $error = 'Informe seu nome.';
    } elseif ($whatsapp === '') {
        $error = 'Informe seu WhatsApp.';
    } elseif ($cidade === '') {
        $error = 'Informe sua cidade.';
    } elseif ($desde !== '' && !preg_match('/^\d{4}$/', $desde)) {
        $error = 'O campo "Desde" deve conter um ano com 4 dígitos.';
    } elseif ($desde !== '') {
        $ano = (int) $desde;
        $anoAtual = (int) date('Y');
        if ($ano < 1900 || $ano > $anoAtual) {
            $error = 'Informe um ano válido no campo "Desde".';
        }
    }

    if ($error === '' && $formValues['instagram'] !== '' && $instagram === '') {
        $error = 'Instagram inválido.';
    }
    if ($error === '' && $formValues['site_url'] !== '' && $siteUrl === '') {
        $error = 'Site inválido.';
    }
    if ($error === '' && $formValues['facebook'] !== '' && $facebook === '') {
        $error = 'Facebook inválido.';
    }
    if ($error === '' && $formValues['youtube'] !== '' && $youtube === '') {
        $error = 'YouTube inválido.';
    }

    $keptWorkPhotos = [];
    if (isset($_POST['keep_work_photos']) && is_array($_POST['keep_work_photos'])) {
        $allowedCurrent = array_flip($existingWorkPhotos);
        foreach ($_POST['keep_work_photos'] as $photoPath) {
            $photoPath = trim((string) $photoPath);
            if ($photoPath !== '' && isset($allowedCurrent[$photoPath])) {
                $keptWorkPhotos[] = $photoPath;
            }
        }
    }

    $profilePhotoPath = is_string($existingProfilePhoto) ? trim($existingProfilePhoto) : '';
    if ($error === '') {
        $profileUploadError = '';
        $uploadedProfile = getUploadedProfilePhotoPath($_FILES['foto_perfil'] ?? [], $profileUploadError);
        if ($profileUploadError !== '') {
            $error = $profileUploadError;
        } elseif ($uploadedProfile !== null) {
            $profilePhotoPath = $uploadedProfile;
        }
    }

    $uploadedWorkPhotos = [];
    if ($error === '') {
        $workUploadError = '';
        $uploadedWorkPhotos = saveUploadedWorkPhotos($_FILES['fotos_trabalhos'] ?? [], $workUploadError);
        if ($workUploadError !== '') {
            $error = $workUploadError;
        }
    }

    $allWorkPhotos = array_values(array_unique(array_merge($keptWorkPhotos, $uploadedWorkPhotos)));
    if ($error === '' && count($allWorkPhotos) > 6) {
        $error = 'Você pode manter/enviar no máximo 6 fotos de trabalhos.';
    }

    if ($error === '') {
        try {
            $workPhotosJson = json_encode($allWorkPhotos, JSON_UNESCAPED_SLASHES);
            $online = $formValues['online'] ? 1 : 0;

            if ($existingProfile && isset($existingProfile['id'])) {
                $stmt = $pdo->prepare(
                    'UPDATE profissionais
                     SET nome = :nome,
                         cidade = :cidade,
                         bairro = :bairro,
                         tags = :tags,
                         descricao = :descricao,
                         desde = :desde,
                         whatsapp = :whatsapp,
                         instagram = :instagram,
                         site_url = :site_url,
                         facebook = :facebook,
                         youtube = :youtube,
                         online = :online,
                         foto_perfil = :foto_perfil,
                         fotos_trabalhos = :fotos_trabalhos
                     WHERE id = :id AND user_id = :user_id'
                );

                $stmt->execute([
                    ':nome' => $nome,
                    ':cidade' => $cidade,
                    ':bairro' => $bairro !== '' ? $bairro : null,
                    ':tags' => $tags !== '' ? $tags : null,
                    ':descricao' => $descricao !== '' ? $descricao : null,
                    ':desde' => $desde !== '' ? (int) $desde : null,
                    ':whatsapp' => $whatsapp !== '' ? $whatsapp : null,
                    ':instagram' => $instagram !== '' ? $instagram : null,
                    ':site_url' => $siteUrl !== '' ? $siteUrl : null,
                    ':facebook' => $facebook !== '' ? $facebook : null,
                    ':youtube' => $youtube !== '' ? $youtube : null,
                    ':online' => $online,
                    ':foto_perfil' => $profilePhotoPath !== '' ? $profilePhotoPath : null,
                    ':fotos_trabalhos' => $workPhotosJson,
                    ':id' => (int) $existingProfile['id'],
                    ':user_id' => (int) $authUserId,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO profissionais (
                        nome, cidade, bairro, tags, descricao, desde, whatsapp,
                        instagram, site_url, facebook, youtube, online,
                        foto_perfil, fotos_trabalhos, nota, user_id, public_id
                    ) VALUES (
                        :nome, :cidade, :bairro, :tags, :descricao, :desde, :whatsapp,
                        :instagram, :site_url, :facebook, :youtube, :online,
                        :foto_perfil, :fotos_trabalhos, 0.0, :user_id, :public_id
                    )'
                );

                $stmt->execute([
                    ':nome' => $nome,
                    ':cidade' => $cidade,
                    ':bairro' => $bairro !== '' ? $bairro : null,
                    ':tags' => $tags !== '' ? $tags : null,
                    ':descricao' => $descricao !== '' ? $descricao : null,
                    ':desde' => $desde !== '' ? (int) $desde : null,
                    ':whatsapp' => $whatsapp !== '' ? $whatsapp : null,
                    ':instagram' => $instagram !== '' ? $instagram : null,
                    ':site_url' => $siteUrl !== '' ? $siteUrl : null,
                    ':facebook' => $facebook !== '' ? $facebook : null,
                    ':youtube' => $youtube !== '' ? $youtube : null,
                    ':online' => $online,
                    ':foto_perfil' => $profilePhotoPath !== '' ? $profilePhotoPath : null,
                    ':fotos_trabalhos' => $workPhotosJson,
                    ':user_id' => (int) $authUserId,
                    ':public_id' => generateUniquePublicId($pdo),
                ]);
            }

            $ownerStmt = $pdo->prepare('SELECT * FROM profissionais WHERE user_id = :user_id LIMIT 1');
            $ownerStmt->execute([':user_id' => $authUserId]);
            $existingProfile = $ownerStmt->fetch() ?: null;

            if ($existingProfile) {
                $formValues['nome'] = (string) ($existingProfile['nome'] ?? '');
                $formValues['cidade'] = (string) ($existingProfile['cidade'] ?? '');
                $formValues['bairro'] = (string) ($existingProfile['bairro'] ?? '');
                $formValues['tags'] = (string) ($existingProfile['tags'] ?? '');
                $formValues['descricao'] = (string) ($existingProfile['descricao'] ?? '');
                $formValues['desde'] = (string) ($existingProfile['desde'] ?? '');
                $formValues['whatsapp'] = (string) ($existingProfile['whatsapp'] ?? '');
                $formValues['instagram'] = (string) ($existingProfile['instagram'] ?? '');
                $formValues['site_url'] = (string) ($existingProfile['site_url'] ?? '');
                $formValues['facebook'] = (string) ($existingProfile['facebook'] ?? '');
                $formValues['youtube'] = (string) ($existingProfile['youtube'] ?? '');
                $formValues['online'] = ((int) ($existingProfile['online'] ?? 1)) === 1;
            }

            $existingProfilePhoto = isset($existingProfile['foto_perfil']) ? trim((string) $existingProfile['foto_perfil']) : null;
            $photosRaw = (string) ($existingProfile['fotos_trabalhos'] ?? '');
            $decoded = json_decode($photosRaw, true);
            $existingWorkPhotos = [];
            if (is_array($decoded)) {
                $existingWorkPhotos = array_values(array_filter(array_map(static function ($item) {
                    return is_string($item) ? trim($item) : '';
                }, $decoded)));
            }

            $success = 'Perfil salvo com sucesso.';
        } catch (Throwable $e) {
            $error = 'Erro ao salvar o perfil: ' . $e->getMessage();
        }
    }
}

$csrf = ensureCsrfToken();
$activeStep = 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedStep = (int) ($_POST['current_step'] ?? 1);
    $activeStep = max(1, min(4, $postedStep));
    if ($success !== '') {
        $activeStep = 4;
    }
}
$profileLink = '';
$profilePublicId = trim((string) ($existingProfile['public_id'] ?? ''));
if (preg_match('/^[a-f0-9]{32}$/', $profilePublicId)) {
    $profileLink = appPath('/access/perfil.php?p=' . rawurlencode($profilePublicId));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Profissionais</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/theme.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .step-panel-enter {
            animation: stepFadeIn .28s ease both;
        }
        @keyframes stepFadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (max-width: 768px) {
            .mobile-sticky-actions {
                position: sticky;
                bottom: 0.75rem;
                background: rgba(255, 255, 255, 0.96);
                backdrop-filter: blur(8px);
                border: 1px solid #e2e8f0;
                border-radius: 1rem;
                padding: 0.75rem;
                margin-left: -0.25rem;
                margin-right: -0.25rem;
            }
        }
        @media (prefers-reduced-motion: reduce) {
            .step-panel-enter { animation: none; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 min-h-screen">
    <div class="max-w-5xl mx-auto px-4 sm:px-5 py-6 md:py-10">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">Cadastro de Profissionais</h1>
                <p class="text-slate-500 mt-2">Logado como <?php echo htmlspecialchars($authUserName !== '' ? $authUserName : ('usuário #' . (string) $authUserId), ENT_QUOTES, 'UTF-8'); ?>.</p>
            </div>
            <div class="flex flex-wrap gap-2 sm:gap-3">
                <a href="<?php echo htmlspecialchars(appPath('/access/painel.php'), ENT_QUOTES, 'UTF-8'); ?>" class="px-3 sm:px-5 py-2.5 rounded-xl border border-slate-300 text-slate-700 font-semibold text-xs sm:text-sm hover:bg-slate-50">Ver painel</a>
                <?php if ($profileLink !== ''): ?>
                    <a href="<?php echo htmlspecialchars($profileLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="px-3 sm:px-5 py-2.5 rounded-xl border border-blue-300 text-blue-700 font-semibold text-xs sm:text-sm hover:bg-blue-50">Ver perfil</a>
                <?php else: ?>
                    <span class="px-3 sm:px-5 py-2.5 rounded-xl border border-slate-200 text-slate-400 font-semibold text-xs sm:text-sm cursor-not-allowed" title="Salve o perfil para visualizar">Ver perfil</span>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars(appPath('/access/logout.php'), ENT_QUOTES, 'UTF-8'); ?>" class="px-3 sm:px-5 py-2.5 rounded-xl border border-red-200 text-red-500 font-semibold text-xs sm:text-sm hover:bg-red-50">Sair</a>
            </div>
        </header>

        <section class="mt-10 px-2 sm:px-8">
            <div class="flex items-start justify-between gap-3" id="stepper">
                <div class="flex-1 flex items-center gap-3">
                    <div data-dot="1" class="w-8 h-8 rounded-full border text-sm font-bold flex items-center justify-center">1</div>
                    <div data-line="1" class="h-0.5 flex-1 bg-slate-300"></div>
                </div>
                <div class="flex-1 flex items-center gap-3">
                    <div data-dot="2" class="w-8 h-8 rounded-full border text-sm font-bold flex items-center justify-center">2</div>
                    <div data-line="2" class="h-0.5 flex-1 bg-slate-300"></div>
                </div>
                <div class="flex-1 flex items-center gap-3">
                    <div data-dot="3" class="w-8 h-8 rounded-full border text-sm font-bold flex items-center justify-center">3</div>
                    <div data-line="3" class="h-0.5 flex-1 bg-slate-300"></div>
                </div>
                <div class="w-8 flex items-center justify-center">
                    <div data-dot="4" class="w-8 h-8 rounded-full border text-sm font-bold flex items-center justify-center">4</div>
                </div>
            </div>
            <div class="mt-3 grid grid-cols-4 text-center text-xs font-semibold text-slate-400">
                <span data-label="1">Informações</span>
                <span data-label="2">Localização</span>
                <span data-label="3">Serviços</span>
                <span data-label="4">Finalizar</span>
            </div>
            <div class="mt-4">
                <div class="h-1.5 rounded-full bg-slate-200 overflow-hidden">
                    <div id="stepProgressBar" class="h-full bg-blue-600 transition-all duration-300" style="width:25%"></div>
                </div>
                <p id="stepProgressText" class="mt-2 text-xs text-slate-500 font-semibold">Etapa 1 de 4</p>
            </div>
        </section>

        <main class="mt-8">
            <?php if ($error !== ''): ?>
                <div class="mb-4 rounded-xl border border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm font-medium">
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="mb-4 rounded-xl border border-green-200 bg-green-50 text-green-700 px-4 py-3 text-sm font-medium">
                    <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" id="profileForm" class="bg-white rounded-2xl md:rounded-3xl border border-slate-200 shadow-sm p-4 sm:p-6 md:p-8">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="current_step" id="current_step" value="<?php echo (int) $activeStep; ?>">
                <input type="hidden" name="youtube" value="<?php echo htmlspecialchars($formValues['youtube'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="online" value="1">

                <section data-step="1" class="step-panel space-y-6">
                    <h2 class="text-2xl md:text-[1.85rem] font-extrabold leading-tight">1. Informações do Profissional</h2>
                    <div>
                        <label class="block text-base md:text-[1.35rem] font-semibold mb-2">Nome Completo</label>
                        <input id="nome" name="nome" type="text" autocomplete="name" required value="<?php echo htmlspecialchars($formValues['nome'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base md:text-[1.3rem] font-medium focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-500">
                    </div>
                    <div class="rounded-2xl border border-dashed border-slate-300 p-5 flex flex-col sm:flex-row gap-4 sm:items-center">
                        <div class="w-20 h-20 rounded-full bg-slate-200 border border-slate-300 flex items-center justify-center overflow-hidden">
                            <?php if (is_string($existingProfilePhoto) && trim($existingProfilePhoto) !== ''): ?>
                                <img src="<?php echo htmlspecialchars($existingProfilePhoto, ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-full object-cover" alt="Foto de Perfil">
                            <?php else: ?>
                                <span class="font-bold text-3xl text-slate-500">FP</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-xl md:text-2xl font-bold">Foto de Perfil</h3>
                            <p class="text-sm md:text-[1.2rem] text-slate-500 mt-1">Esta foto será salva no seu perfil público.</p>
                            <label class="inline-block mt-3 px-4 py-2 rounded-xl border border-blue-300 bg-blue-50 text-blue-700 font-semibold cursor-pointer text-sm md:text-[1.1rem]">
                                Selecionar foto
                                <input type="file" name="foto_perfil" accept="image/jpeg,image/png,image/webp" class="hidden">
                            </label>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-base md:text-[1.35rem] font-semibold mb-2">WhatsApp (com DDD)</label>
                            <input id="whatsapp" name="whatsapp" type="text" inputmode="numeric" autocomplete="tel-national" required value="<?php echo htmlspecialchars($formValues['whatsapp'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex: 11999998888" class="w-full rounded-2xl border border-slate-300 bg-slate-100 px-4 py-3 text-base md:text-[1.3rem] font-medium focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-base md:text-[1.35rem] font-semibold mb-2">Atua desde (ano)</label>
                            <input id="desde" name="desde" type="number" min="1900" max="<?php echo (int) date('Y'); ?>" value="<?php echo htmlspecialchars($formValues['desde'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base md:text-[1.3rem] font-medium focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-500">
                        </div>
                    </div>
                    <div class="pt-2">
                        <p class="text-[1.15rem] font-semibold text-slate-600 mb-3">Contatos opcionais</p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-[1.1rem] font-semibold mb-2">Instagram</label>
                                <input id="instagram" name="instagram" type="text" value="<?php echo htmlspecialchars($formValues['instagram'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="@usuario ou URL completa" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-[1rem] font-medium focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-[1.1rem] font-semibold mb-2">Site</label>
                                <input id="site_url" name="site_url" type="text" value="<?php echo htmlspecialchars($formValues['site_url'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="www.seusite.com" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-[1rem] font-medium focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-[1.1rem] font-semibold mb-2">Facebook</label>
                                <input id="facebook" name="facebook" type="text" value="<?php echo htmlspecialchars($formValues['facebook'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="@usuario ou URL completa" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-[1rem] font-medium focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-500">
                            </div>
                        </div>
                    </div>
                </section>

                <section data-step="2" class="step-panel space-y-6 hidden">
                    <h2 class="text-2xl md:text-[1.85rem] font-extrabold leading-tight">2. Localização de Atendimento</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-base md:text-[1.35rem] font-semibold mb-2">Cidade</label>
                            <input id="cidade" name="cidade" required type="text" autocomplete="address-level2" value="<?php echo htmlspecialchars($formValues['cidade'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex: São Paulo" class="w-full rounded-2xl border border-slate-300 bg-slate-100 px-4 py-3 text-base md:text-[1.3rem] font-medium focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-base md:text-[1.35rem] font-semibold mb-2">Estado</label>
                            <input id="bairro" name="bairro" type="text" autocomplete="address-level1" value="<?php echo htmlspecialchars($formValues['bairro'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex: SP" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base md:text-[1.3rem] font-medium focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-500">
                        </div>
                    </div>
                </section>

                <section data-step="3" class="step-panel space-y-6 hidden">
                    <h2 class="text-2xl md:text-[1.85rem] font-extrabold leading-tight">3. Especialidades (Tags)</h2>
                    <p class="text-sm md:text-[1.2rem] text-slate-500">Digite as tags separadas por vírgula (ex: Elétrica, Ar-condicionado, Refrigeração).</p>
                    <div>
                        <label class="block text-base md:text-[1.35rem] font-semibold mb-2">Tags</label>
                        <input id="tags" name="tags" type="text" value="<?php echo htmlspecialchars($formValues['tags'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex: Elétrica, Ar-condicionado, Encanamento" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base md:text-[1.2rem] font-medium focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-500">
                    </div>

                    <div>
                        <label class="block text-base md:text-[1.35rem] font-semibold mb-2">Descrição Profissional</label>
                        <textarea id="descricao" name="descricao" rows="4" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base md:text-[1.2rem] font-medium focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-500"><?php echo htmlspecialchars($formValues['descricao'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <p class="text-sm md:text-[1rem] text-slate-400 mt-2">Mínimo de 20 caracteres.</p>
                    </div>

                    <div>
                        <label class="block text-base md:text-[1.35rem] font-semibold mb-2">Fotos de trabalhos já realizados (máximo 6)</label>
                        <input type="file" name="fotos_trabalhos[]" multiple accept="image/jpeg,image/png,image/webp" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm md:text-[1rem]">
                        <p class="text-sm md:text-[1rem] text-slate-400 mt-2">Envie até 6 imagens (JPG, PNG ou WEBP, máx. 5MB cada).</p>
                    </div>
                    <?php if (!empty($existingWorkPhotos)): ?>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                            <?php foreach ($existingWorkPhotos as $idx => $photoPath): ?>
                                <label class="relative rounded-xl overflow-hidden border border-slate-300">
                                    <img src="<?php echo htmlspecialchars($photoPath, ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-24 object-cover" alt="Foto <?php echo (int) ($idx + 1); ?>">
                                    <span class="absolute inset-x-0 bottom-0 bg-black/60 text-white text-[0.9rem] px-2 py-1 inline-flex items-center gap-2">
                                        <input type="checkbox" name="keep_work_photos[]" value="<?php echo htmlspecialchars($photoPath, ENT_QUOTES, 'UTF-8'); ?>" checked>
                                        Manter
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section data-step="4" class="step-panel space-y-6 hidden">
                    <h2 class="text-2xl md:text-[1.85rem] font-extrabold leading-tight">4. Finalizar</h2>
                    <div class="rounded-3xl border border-slate-200 bg-slate-50 px-6 py-10 text-center">
                        <div class="w-12 h-12 mx-auto rounded-full bg-blue-600 text-white flex items-center justify-center text-2xl">&#10003;</div>
                        <h3 class="text-2xl md:text-[1.85rem] font-extrabold mt-5">Tudo Pronto!</h3>
                        <p class="text-base md:text-[1.15rem] text-slate-500 mt-2">Ao confirmar, seu perfil será salvo com segurança e vinculado à sua conta.</p>
                    </div>
                </section>

                <div class="mobile-sticky-actions mt-8 pt-3 md:pt-6 border-t border-slate-200 flex items-center justify-between gap-2">
                    <button type="button" id="btnPrev" class="px-4 md:px-7 py-2.5 text-base md:text-[1.15rem] font-bold text-slate-400">Voltar</button>
                    <button type="button" id="btnNext" class="px-5 md:px-7 py-2.5 rounded-2xl bg-blue-600 text-white text-base md:text-[1.15rem] font-bold hover:bg-blue-700">Próximo</button>
                    <button type="submit" id="btnSubmit" class="hidden px-5 md:px-7 py-2.5 rounded-2xl bg-blue-700 text-white text-base md:text-[1.15rem] font-bold hover:bg-blue-800">Confirmar e Salvar</button>
                </div>
            </form>
        </main>
    </div>

    <script>
        (function () {
            const form = document.getElementById('profileForm');
            const stepPanels = Array.from(document.querySelectorAll('.step-panel'));
            const dots = [1, 2, 3, 4].map(n => document.querySelector('[data-dot="' + n + '"]'));
            const labels = [1, 2, 3, 4].map(n => document.querySelector('[data-label="' + n + '"]'));
            const lines = [1, 2, 3].map(n => document.querySelector('[data-line="' + n + '"]'));
            const btnPrev = document.getElementById('btnPrev');
            const btnNext = document.getElementById('btnNext');
            const btnSubmit = document.getElementById('btnSubmit');
            const currentStepInput = document.getElementById('current_step');
            const stepProgressBar = document.getElementById('stepProgressBar');
            const stepProgressText = document.getElementById('stepProgressText');
            let currentStep = Number(currentStepInput.value) || 1;

            function validateStep(step) {
                if (step === 1) {
                    const nome = document.getElementById('nome');
                    const whatsapp = document.getElementById('whatsapp');
                    if (!nome.value.trim()) {
                        nome.focus();
                        return false;
                    }
                    if (!whatsapp.value.trim()) {
                        whatsapp.focus();
                        return false;
                    }
                }
                if (step === 2) {
                    const cidade = document.getElementById('cidade');
                    if (!cidade.value.trim()) {
                        cidade.focus();
                        return false;
                    }
                }
                if (step === 3) {
                    const descricao = document.getElementById('descricao');
                    if (descricao.value.trim().length < 20) {
                        descricao.focus();
                        alert('A descrição precisa ter no mínimo 20 caracteres.');
                        return false;
                    }
                }
                return true;
            }

            function updateStepper() {
                stepPanels.forEach((panel, idx) => {
                    const isActive = idx + 1 === currentStep;
                    panel.classList.toggle('hidden', !isActive);
                    panel.classList.remove('step-panel-enter');
                    if (isActive) {
                        requestAnimationFrame(() => panel.classList.add('step-panel-enter'));
                    }
                });

                dots.forEach((dot, idx) => {
                    const n = idx + 1;
                    dot.className = 'w-8 h-8 rounded-full border text-sm font-bold flex items-center justify-center';
                    if (n <= currentStep) {
                        dot.classList.add('bg-blue-600', 'border-blue-600', 'text-white');
                    } else {
                        dot.classList.add('bg-white', 'border-slate-300', 'text-slate-400');
                    }
                });

                labels.forEach((label, idx) => {
                    const n = idx + 1;
                    label.className = 'text-xs font-semibold';
                    if (n <= currentStep) {
                        label.classList.add('text-blue-600');
                    } else {
                        label.classList.add('text-slate-400');
                    }
                });

                lines.forEach((line, idx) => {
                    line.className = 'h-0.5 flex-1';
                    if (idx + 1 < currentStep) {
                        line.classList.add('bg-blue-400');
                    } else {
                        line.classList.add('bg-slate-300');
                    }
                });

                btnPrev.classList.toggle('opacity-40', currentStep === 1);
                btnPrev.disabled = currentStep === 1;
                btnNext.classList.toggle('hidden', currentStep === 4);
                btnSubmit.classList.toggle('hidden', currentStep !== 4);
                if (stepProgressBar) {
                    stepProgressBar.style.width = String((currentStep / 4) * 100) + '%';
                }
                if (stepProgressText) {
                    stepProgressText.textContent = 'Etapa ' + String(currentStep) + ' de 4';
                }
                currentStepInput.value = String(currentStep);
            }

            btnPrev.addEventListener('click', function () {
                if (currentStep > 1) {
                    currentStep -= 1;
                    updateStepper();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });

            btnNext.addEventListener('click', function () {
                if (!validateStep(currentStep)) {
                    return;
                }
                if (currentStep < 4) {
                    currentStep += 1;
                    updateStepper();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });

            form.addEventListener('submit', function () {
                currentStepInput.value = '4';
            });
            updateStepper();
        })();
    </script>
</body>
</html>

