<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/security.php';
initializeWebRequest();
require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/controllers/AdminController.php';
require_once __DIR__ . '/../app/views/layouts/helpers.php';

$pdo = getDatabaseConnection();
$user = getAuthenticatedUser($pdo);
if ($user === null) {
    header('Location: /');
    exit;
}
if ($user['role_id'] !== 4) {
    http_response_code(403);
    exit('Solo el administrador puede gestionar usuarios y roles.');
}

$token = csrfToken();
$errorMessage = '';
$success = ($_GET['saved'] ?? '') === '1';
$roleStmt = $pdo->query('SELECT id, name FROM roles ORDER BY id');
$roles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitud no válida. Recarga la página e inténtalo de nuevo.');
    }

    $idInput = $_POST['user_id'] ?? '';
    $name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $roleInput = $_POST['role_id'] ?? '';
    $password = $_POST['password'] ?? '';
    $activeInput = $_POST['is_active'] ?? '';

    if (
        !is_string($idInput) ||
        ($idInput !== '' && (!ctype_digit($idInput) || (int) $idInput < 1)) ||
        !is_string($name) ||
        !is_string($email) ||
        !is_string($roleInput) ||
        !ctype_digit($roleInput) ||
        !is_string($password) ||
        !in_array($activeInput, ['0', '1'], true)
    ) {
        $errorMessage = 'Los datos enviados no son válidos.';
    } else {
        try {
            $pdo->beginTransaction();
            saveUserAccount(
                $pdo,
                $idInput === '' ? null : (int) $idInput,
                $user['id'],
                $name,
                $email,
                (int) $roleInput,
                $activeInput === '1',
                $password === '' ? null : $password,
                requestIpAddress()
            );
            $pdo->commit();
            header('Location: /usuarios.php?saved=1');
            exit;
        } catch (InvalidArgumentException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                $errorMessage = 'Ese correo ya está registrado.';
            } else {
                error_log('Error al guardar usuario: ' . $exception->getMessage());
                $errorMessage = 'No se pudo guardar la cuenta. Inténtalo nuevamente.';
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Error al guardar usuario: ' . $exception->getMessage());
            $errorMessage = 'No se pudo guardar la cuenta. Inténtalo nuevamente.';
        }
    }
}

$pageInput = $_GET['page'] ?? '1';
$page = is_string($pageInput) && ctype_digit($pageInput)
    ? max(1, (int) $pageInput)
    : 1;
$pageSize = 50;
$userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$pageCount = max(1, (int) ceil($userCount / $pageSize));
$page = min($page, $pageCount);
$stmt = $pdo->prepare(
    'SELECT u.id, u.full_name, u.email, u.role_id, u.is_active, u.created_at
     FROM users u
     ORDER BY u.id
     LIMIT :limit OFFSET :offset'
);
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usuarios y roles | AnniDesk</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php
$currentPage = '/usuarios.php';
require __DIR__ . '/../app/views/layouts/navigation.php';
?>
<main class="tickets-wrap">
    <header class="tickets-toolbar">
        <div><h1>Usuarios y roles</h1><p>Solo administración puede cambiar cuentas y permisos.</p></div>
    </header>
    <section class="tickets-table-wrap">
        <?php if ($success): ?><p class="notice notice-success" role="status">La cuenta se guardó correctamente.</p><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><p class="notice notice-error" role="alert"><?= e($errorMessage) ?></p><?php endif; ?>

        <h2>Crear cuenta</h2>
        <form class="admin-form" method="post" action="/usuarios.php">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <label>Nombre completo <input name="full_name" maxlength="150" required></label>
            <label>Correo electrónico <input type="email" name="email" maxlength="254" required></label>
            <label>Rol
                <select name="role_id" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= (int) $role['id'] ?>"><?= e($role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Contraseña inicial <input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
            <label>Estado
                <select name="is_active"><option value="1">Activa</option><option value="0">Inactiva</option></select>
            </label>
            <button type="submit">Crear cuenta</button>
        </form>

        <h2>Cuentas existentes</h2>
        <div class="table-scroll">
            <table class="tickets-table">
                <thead><tr><th>Cuenta</th><th>Rol y estado</th><th>Contraseña nueva (opcional)</th><th>Guardar</th></tr></thead>
                <tbody>
                <?php foreach ($users as $account): ?>
                    <tr>
                        <td>
                            <form id="user-<?= (int) $account['id'] ?>" method="post" action="/usuarios.php">
                                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                <label>Nombre <input name="full_name" maxlength="150" value="<?= e($account['full_name']) ?>" required></label>
                                <label>Correo <input type="email" name="email" maxlength="254" value="<?= e($account['email']) ?>" required></label>
                            </form>
                        </td>
                        <td>
                            <label>Rol
                                <select form="user-<?= (int) $account['id'] ?>" name="role_id">
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= (int) $role['id'] ?>" <?= (int) $account['role_id'] === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Estado
                                <select form="user-<?= (int) $account['id'] ?>" name="is_active">
                                    <option value="1" <?= (int) $account['is_active'] === 1 ? 'selected' : '' ?>>Activa</option>
                                    <option value="0" <?= (int) $account['is_active'] === 0 ? 'selected' : '' ?>>Inactiva</option>
                                </select>
                            </label>
                        </td>
                        <td><label>Contraseña
                            <input form="user-<?= (int) $account['id'] ?>" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password">
                        </label></td>
                        <td><button form="user-<?= (int) $account['id'] ?>" type="submit">Guardar</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pageCount > 1): ?><nav aria-label="Paginación"><?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">Anterior</a><?php endif; ?> Página <?= $page ?> de <?= $pageCount ?> <?php if ($page < $pageCount): ?><a href="?page=<?= $page + 1 ?>">Siguiente</a><?php endif; ?></nav><?php endif; ?>
    </section>
</main>
</body>
</html>
