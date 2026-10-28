<?php
declare(strict_types=1);

function initializeWebRequest(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    startSecureSession();
}

function requestIpAddress(): ?string
{
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

    return is_string($ipAddress) &&
        filter_var($ipAddress, FILTER_VALIDATE_IP) !== false
        ? $ipAddress
        : null;
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function isValidCsrfToken(mixed $submittedToken): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? null;

    return is_string($submittedToken)
        && is_string($sessionToken)
        && $sessionToken !== ''
        && hash_equals($sessionToken, $submittedToken);
}
