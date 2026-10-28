<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/security.php';
initializeWebRequest();
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/AuditService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Solicitud no válida. Recarga la página e inténtalo de nuevo.');
}

$actorId = $_SESSION['user']['id'] ?? null;
if (is_int($actorId) && $actorId > 0) {
    recordAuditEntry(
        getDatabaseConnection(),
        $actorId,
        'auth.logout',
        'user',
        $actorId,
        [],
        requestIpAddress()
    );
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => 'Lax',
    ]);
}

session_destroy();

header('Location: /');
exit;
