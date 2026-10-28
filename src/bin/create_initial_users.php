<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/UserService.php';

if (PHP_SAPI !== 'cli' || !stream_isatty(STDIN)) {
    fwrite(STDERR, "Este comando debe ejecutarse desde una terminal interactiva.\n");
    exit(1);
}

$initialUsers = [
    [
        'name' => 'Solicitante AnniDesk',
        'email' => 'solicitante@anidesk.com',
        'role_id' => 1,
    ],
    [
        'name' => 'Agente de soporte',
        'email' => 'supportagent@anidesk.com',
        'role_id' => 2,
    ],
    [
        'name' => 'Joel Supervisor',
        'email' => 'joelsupervisor@anidesk.com',
        'role_id' => 3,
    ],
    [
        'name' => 'Anit Administrador',
        'email' => 'anitadmin@anidesk.com',
        'role_id' => 4,
    ],
    [
        'name' => 'Vinicio Auditor',
        'email' => 'vinicioauditor@anidesk.com',
        'role_id' => 5,
    ],
];

function readNewPassword(string $email): string
{
    while (true) {
        fwrite(STDOUT, "Contraseña nueva para {$email} (mínimo 12 caracteres): ");

        exec('stty -echo', $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('No se pudo ocultar la entrada de contraseña.');
        }

        try {
            $password = fgets(STDIN);
            fwrite(STDOUT, "\nConfirma la contraseña: ");
            $confirmation = fgets(STDIN);
        } finally {
            exec('stty echo');
            fwrite(STDOUT, "\n");
        }

        if ($password === false || $confirmation === false) {
            throw new RuntimeException('No se pudo leer la contraseña.');
        }

        $password = rtrim($password, "\r\n");
        $confirmation = rtrim($confirmation, "\r\n");

        if ($password !== $confirmation) {
            fwrite(STDERR, "Las contraseñas no coinciden. Inténtalo otra vez.\n");
            continue;
        }

        if (mb_strlen($password) < 12 || strlen($password) > 72) {
            fwrite(STDERR, "Usa al menos 12 caracteres y no más de 72 bytes.\n");
            continue;
        }

        return $password;
    }
}

try {
    $pdo = getDatabaseConnection();
    $findUser = $pdo->prepare(
        'SELECT role_id FROM users WHERE email = :email LIMIT 1'
    );
    $pendingUsers = [];

    foreach ($initialUsers as $initialUser) {
        $findUser->execute(['email' => $initialUser['email']]);
        $existingUser = $findUser->fetch();

        if ($existingUser) {
            if ((int) $existingUser['role_id'] !== $initialUser['role_id']) {
                throw new RuntimeException(
                    'La cuenta ' . $initialUser['email'] .
                    ' ya existe con otro rol; no se modificó.'
                );
            }

            fwrite(STDOUT, "Ya existe: {$initialUser['email']}\n");
            continue;
        }

        $initialUser['password'] = readNewPassword($initialUser['email']);
        $pendingUsers[] = $initialUser;
    }

    if ($pendingUsers === []) {
        fwrite(STDOUT, "Las cinco cuentas iniciales ya están creadas.\n");
        exit(0);
    }

    $pdo->beginTransaction();

    foreach ($pendingUsers as $user) {
        createUser(
            $pdo,
            $user['name'],
            $user['email'],
            $user['password'],
            $user['role_id']
        );
    }

    $pdo->commit();
    fwrite(STDOUT, "Se crearon " . count($pendingUsers) . " cuentas iniciales.\n");
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "No se pudieron crear las cuentas: " . $exception->getMessage() . "\n");
    exit(1);
}
