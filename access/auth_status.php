<?php
require_once __DIR__ . '/../secure/auth.php';
require_once __DIR__ . '/../secure/config.php';

startSecureSession();

$profileEditUrl = appPath('/secure/save_profile.php');
$loginUrl = appPath('/access/login.php?mode=login&next=' . rawurlencode($profileEditUrl));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

echo json_encode([
    'authenticated' => isAuthenticated(),
    'edit_profile_url' => $profileEditUrl,
    'login_url' => $loginUrl,
], JSON_UNESCAPED_SLASHES);

