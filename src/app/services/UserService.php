<?php
declare(strict_types=1);

function createUser(
    PDO $pdo,
    string $fullName,
    string $email,
    string $password,
    int $roleId,
    bool $isActive = true
): int {
    $fullName = trim($fullName);
    $email = strtolower(trim($email));

    if ($fullName === '' || mb_strlen($fullName) > 150) {
        throw new InvalidArgumentException(
            'El nombre es obligatorio y no debe superar los 150 caracteres.'
        );
    }

    if (
        strlen($email) > 254 ||
        filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new InvalidArgumentException('El correo electrónico no es válido.');
    }

    if (mb_strlen($password) < 12 || strlen($password) > 72) {
        throw new InvalidArgumentException(
            'La contraseña debe tener al menos 12 caracteres y no superar los 72 bytes.'
        );
    }

    if ($roleId < 1) {
        throw new InvalidArgumentException('El rol seleccionado no es válido.');
    }

    $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE id = :id');
    $roleStmt->execute(['id' => $roleId]);

    if (!$roleStmt->fetch()) {
        throw new InvalidArgumentException('El rol seleccionado no existe.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($passwordHash === false) {
        throw new RuntimeException('No se pudo proteger la contraseña.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users
            (role_id, full_name, email, password_hash, is_active)
         VALUES
            (:role_id, :full_name, :email, :password_hash, :is_active)'
    );
    $stmt->execute([
        'role_id' => $roleId,
        'full_name' => $fullName,
        'email' => $email,
        'password_hash' => $passwordHash,
        'is_active' => (int) $isActive,
    ]);

    return (int) $pdo->lastInsertId();
}
