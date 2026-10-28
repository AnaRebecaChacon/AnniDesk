<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/security.php';
initializeWebRequest();

require_once __DIR__ . '/../app/controllers/AuthController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Solicitud no válida. Recarga la página e inténtalo de nuevo.');
}

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

if (!is_string($email) || !is_string($password)) {
    http_response_code(400);
    exit('Datos no válidos.');
}

try {
    if (attemptLogin($email, $password, requestIpAddress())) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: /dashboard.php');
        exit;
    }

    http_response_code(401);
    $errorMessage = 'Correo electrónico o contraseña incorrectos.';
} catch (Throwable $exception) {
    error_log('Error durante el inicio de sesión: ' . $exception->getMessage());
    http_response_code(500);
    $errorMessage = 'No se pudo iniciar sesión. Inténtalo más tarde.';
}

require __DIR__ . '/../app/views/auth/login.php';
