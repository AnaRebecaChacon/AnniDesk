<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AuditService.php';

function isLoginRateLimited(PDO $pdo, string $ipAddress): bool
{
    $stmt = $pdo->prepare(
        'SELECT locked_until > CURRENT_TIMESTAMP
         FROM auth_login_attempts
         WHERE ip_address = :ip_address'
    );
    $stmt->execute(['ip_address' => $ipAddress]);

    return (int) $stmt->fetchColumn() === 1;
}

function recordLoginFailure(PDO $pdo, string $ipAddress): void
{
    $pdo->prepare(
        'DELETE FROM auth_login_attempts
         WHERE updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)
         LIMIT 1000'
    )->execute();

    $stmt = $pdo->prepare(
        'INSERT INTO auth_login_attempts
            (ip_address, failed_attempts, locked_until)
         VALUES
            (:ip_address, 1, NULL)
         ON DUPLICATE KEY UPDATE
            failed_attempts = LEAST(failed_attempts + 1, 65535),
            locked_until = IF(
                failed_attempts >= 5,
                DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE),
                locked_until
            )'
    );
    $stmt->execute(['ip_address' => $ipAddress]);
}

function getAuthenticatedUser(PDO $pdo): ?array
{
    $userId = $_SESSION['user']['id'] ?? null;

    if (!is_int($userId) || $userId < 1) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, role_id, full_name, email
         FROM users
         WHERE id = :id AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }

    $authenticatedUser = [
        'id' => (int) $user['id'],
        'role_id' => (int) $user['role_id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
    ];

    $_SESSION['user'] = $authenticatedUser;

    return $authenticatedUser;
}

function attemptLogin(
    string $email,
    string $password,
    ?string $ipAddress = null
): bool
{
    $email = strtolower(trim($email));
    if (
        $ipAddress !== null &&
        filter_var($ipAddress, FILTER_VALIDATE_IP) === false
    ) {
        $ipAddress = null;
    }

    $pdo = getDatabaseConnection();

    if ($ipAddress !== null && isLoginRateLimited($pdo, $ipAddress)) {
        return false;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        if ($ipAddress !== null) {
            recordLoginFailure($pdo, $ipAddress);
        }

        return false;
    }

    $sql = 'SELECT id, role_id, full_name, email, password_hash, is_active
            FROM users
            WHERE email = :email
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['email' => $email]);

    $user = $stmt->fetch();

    if (
        !$user ||
        (int) $user['is_active'] !== 1 ||
        !password_verify($password, $user['password_hash'])
    ) {
        if ($ipAddress !== null) {
            recordLoginFailure($pdo, $ipAddress);
        }

        return false;
    }

    if ($ipAddress !== null) {
        $stmt = $pdo->prepare(
            'DELETE FROM auth_login_attempts WHERE ip_address = :ip_address'
        );
        $stmt->execute(['ip_address' => $ipAddress]);
    }

    recordAuditEntry(
        $pdo,
        (int) $user['id'],
        'auth.login_succeeded',
        'user',
        (int) $user['id'],
        ['email' => $user['email']],
        $ipAddress
    );

    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'role_id' => (int) $user['role_id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
    ];

    return true;
}
