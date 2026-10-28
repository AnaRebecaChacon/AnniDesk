<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UserService.php';

function saveUserAccount(
    PDO $pdo,
    ?int $userId,
    int $actorId,
    string $fullName,
    string $email,
    int $roleId,
    bool $isActive,
    ?string $password,
    ?string $ipAddress = null
): void {
    $fullName = trim($fullName);
    $email = strtolower(trim($email));

    if (!$pdo->inTransaction()) {
        throw new RuntimeException(
            'Los cambios de cuenta deben ejecutarse dentro de una transacción.'
        );
    }

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

    if ($roleId < 1) {
        throw new InvalidArgumentException('Selecciona un rol válido.');
    }

    if ($userId === null && $password === null) {
        throw new InvalidArgumentException(
            'La contraseña es obligatoria para una cuenta nueva.'
        );
    }

    if (
        $password !== null &&
        (mb_strlen($password) < 12 || strlen($password) > 72)
    ) {
        throw new InvalidArgumentException(
            'La contraseña debe tener al menos 12 caracteres y no superar los 72 bytes.'
        );
    }

    $pdo->query(
        'SELECT id FROM users
         WHERE role_id = 4 AND is_active = 1
         ORDER BY id
         LIMIT 1
         FOR UPDATE'
    )->fetch();

    $actorStmt = $pdo->prepare(
        'SELECT role_id FROM users WHERE id = :id AND is_active = 1 FOR UPDATE'
    );
    $actorStmt->execute(['id' => $actorId]);
    $actor = $actorStmt->fetch();
    if (!$actor || (int) $actor['role_id'] !== 4) {
        throw new RuntimeException(
            'Solo un administrador activo puede gestionar cuentas.'
        );
    }

    $roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id');
    $roleStmt->execute(['id' => $roleId]);
    if ($roleStmt->fetchColumn() === false) {
        throw new InvalidArgumentException('El rol seleccionado no existe.');
    }

    $oldUser = null;
    if ($userId !== null) {
        $userStmt = $pdo->prepare(
            'SELECT u.id, u.full_name, u.email, u.role_id, u.is_active,
                    r.name AS role_name
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             FOR UPDATE'
        );
        $userStmt->execute(['id' => $userId]);
        $oldUser = $userStmt->fetch();

        if (!$oldUser) {
            throw new RuntimeException('La cuenta solicitada no existe.');
        }

        if (
            $userId === $actorId &&
            (!$isActive || $roleId !== 4)
        ) {
            throw new InvalidArgumentException(
                'No puedes desactivar tu cuenta ni quitarte el rol de administrador.'
            );
        }

        if (
            (int) $oldUser['role_id'] === 4 &&
            (int) $oldUser['is_active'] === 1 &&
            (!$isActive || $roleId !== 4)
        ) {
            $adminCount = (int) $pdo->query(
                'SELECT COUNT(*) FROM users
                 WHERE role_id = 4 AND is_active = 1'
            )->fetchColumn();

            if ($adminCount <= 1) {
                throw new InvalidArgumentException(
                    'Debe permanecer al menos un administrador activo.'
                );
            }
        }
    }

    if ($userId === null) {
        $userId = createUser(
            $pdo,
            $fullName,
            $email,
            $password,
            $roleId,
            $isActive
        );
        $action = 'user.created';
        $oldUser = null;
    } else {
        if ($password !== null) {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new RuntimeException('No se pudo proteger la contraseña.');
            }
        }

        $sql = 'UPDATE users
                SET role_id = :role_id,
                    full_name = :full_name,
                    email = :email,
                    is_active = :is_active';
        if ($password !== null) {
            $sql .= ', password_hash = :password_hash';
        }
        $sql .= ' WHERE id = :id';

        $stmt = $pdo->prepare($sql);
        $values = [
            'role_id' => $roleId,
            'full_name' => $fullName,
            'email' => $email,
            'is_active' => (int) $isActive,
            'id' => $userId,
        ];
        if ($password !== null) {
            $values['password_hash'] = $passwordHash;
        }
        $stmt->execute($values);
        $action = $password === null ? 'user.updated' : 'user.updated_with_password';
    }

    $oldDetails = $oldUser === null ? null : [
        'name' => $oldUser['full_name'],
        'email' => $oldUser['email'],
        'role_id' => (int) $oldUser['role_id'],
        'is_active' => (int) $oldUser['is_active'],
    ];
    recordAuditEntry(
        $pdo,
        $actorId,
        $action,
        'user',
        $userId,
        [
            'before' => $oldDetails,
            'after' => [
                'name' => $fullName,
                'email' => $email,
                'role_id' => $roleId,
                'is_active' => (int) $isActive,
            ],
            'password_changed' => $password !== null,
        ],
        $ipAddress
    );
}

function saveCategory(
    PDO $pdo,
    ?int $categoryId,
    int $actorId,
    string $name,
    string $description,
    bool $isActive,
    ?string $ipAddress = null
): void {
    $name = trim($name);
    $description = trim($description);

    if (!$pdo->inTransaction()) {
        throw new RuntimeException(
            'Los cambios de categoría deben ejecutarse dentro de una transacción.'
        );
    }

    if ($name === '' || mb_strlen($name) > 100) {
        throw new InvalidArgumentException(
            'El nombre es obligatorio y no debe superar los 100 caracteres.'
        );
    }

    if (mb_strlen($description) > 255) {
        throw new InvalidArgumentException(
            'La descripción no debe superar los 255 caracteres.'
        );
    }

    $actorStmt = $pdo->prepare(
        'SELECT role_id FROM users WHERE id = :id AND is_active = 1 FOR UPDATE'
    );
    $actorStmt->execute(['id' => $actorId]);
    $actor = $actorStmt->fetch();
    if (!$actor || (int) $actor['role_id'] !== 4) {
        throw new RuntimeException(
            'Solo un administrador activo puede gestionar categorías.'
        );
    }

    $oldCategory = null;
    if ($categoryId !== null) {
        $categoryStmt = $pdo->prepare(
            'SELECT name, description, is_active
             FROM categories WHERE id = :id FOR UPDATE'
        );
        $categoryStmt->execute(['id' => $categoryId]);
        $oldCategory = $categoryStmt->fetch();
        if (!$oldCategory) {
            throw new RuntimeException('La categoría solicitada no existe.');
        }
    }

    if ($categoryId === null) {
        $stmt = $pdo->prepare(
            'INSERT INTO categories (name, description, is_active)
             VALUES (:name, :description, :is_active)'
        );
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'is_active' => (int) $isActive,
        ]);
        $categoryId = (int) $pdo->lastInsertId();
        $action = 'category.created';
    } else {
        $stmt = $pdo->prepare(
            'UPDATE categories
             SET name = :name, description = :description, is_active = :is_active
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'is_active' => (int) $isActive,
            'id' => $categoryId,
        ]);

        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT id FROM categories WHERE id = :id');
            $exists->execute(['id' => $categoryId]);
            if (!$exists->fetch()) {
                throw new RuntimeException('La categoría solicitada no existe.');
            }
        }
        $action = 'category.updated';
    }

    recordAuditEntry(
        $pdo,
        $actorId,
        $action,
        'category',
        $categoryId,
        [
            'before' => $oldCategory === null ? null : [
                'name' => $oldCategory['name'],
                'description' => $oldCategory['description'],
                'is_active' => (int) $oldCategory['is_active'],
            ],
            'after' => [
                'name' => $name,
                'description' => $description,
                'is_active' => (int) $isActive,
            ],
        ],
        $ipAddress
    );
}
