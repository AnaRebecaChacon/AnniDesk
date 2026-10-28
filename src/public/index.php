<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/security.php';
initializeWebRequest();

header('Content-Type: text/html; charset=UTF-8');

require __DIR__ . '/../app/views/auth/login.php';
