<?php
require_once __DIR__ . '/../secure/auth.php';
require_once __DIR__ . '/../secure/config.php';

startSecureSession();

$defaultNext = appPath('/access/area_profissional.php');
$nextPath = normalizeNextPath($_GET['next'] ?? null, $defaultNext);

header('Location: ' . appPath('/access/login.php?mode=login&next=' . rawurlencode($nextPath)));
exit;
