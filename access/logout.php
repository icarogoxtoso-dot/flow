<?php
require_once __DIR__ . '/../secure/auth.php';
require_once __DIR__ . '/../secure/config.php';
startSecureSession();
logoutUser();
header('Location: ' . appPath('/access/login.php?mode=login'));
exit;
