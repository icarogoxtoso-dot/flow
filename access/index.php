<?php
require_once __DIR__ . '/../secure/config.php';

header('Location: ' . appPath('/access/login.php?mode=login'));
exit;

