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
if (!in_array($user['role_id'], [3, 4, 5], true)) {
    http_response_code(403);
    exit('No tienes permiso para consultar las categorías.');
}

$canManage = $user['role_id'] === 4;
$token = csrfToken();
$errorMessage = '';
$success = ($_GET['saved'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitud no válida. Recarga la página e inténtalo de nuevo.');
    }
    if (!$canManage) {
        http_response_code(403);
        exit('Solo el administrador puede modificar categorías.');
    }

    $idInput = $_POST['category_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    $activeInput = $_POST['is_active'] ?? '';
    if (
        !is_string($idInput) ||
        ($idInput !== '' && (!ctype_digit($idInput) || (int) $idInput < 1)) ||
        !is_string($name) ||
        !is_string($description) ||
        !in_array($activeInput, ['0', '1'], true)
    ) {
        $errorMessage = 'Los datos enviados no son válidos.';
    } else {
        try {
            $pdo->beginTransaction();
            saveCategory(
                $pdo,
                $idInput === '' ? null : (int) $idInput,
                $user['id'],
                $name,
                $description,
                $activeInput === '1',
                requestIpAddress()
            );
            $pdo->commit();
            header('Location: /categorias.php?saved=1');
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
                $errorMessage = 'Ya existe una categoría con ese nombre.';
            } else {
                error_log('Error al guardar categoría: ' . $exception->getMessage());
                $errorMessage = 'No se pudo guardar la categoría.';
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Error al guardar categoría: ' . $exception->getMessage());
            $errorMessage = 'No se pudo guardar la categoría.';
        }
    }
}

$categories = $pdo->query(
    'SELECT id, name, description, is_active, created_at
     FROM categories ORDER BY name'
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Categorías | AnniDesk</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php
$currentPage = '/categorias.php';
require __DIR__ . '/../app/views/layouts/navigation.php';
?>
<main class="tickets-wrap">
    <header class="tickets-toolbar">
        <div><h1>Categorías</h1><p>Organiza los tipos de solicitudes disponibles.</p></div>
    </header>
    <section class="tickets-table-wrap">
        <?php if ($success): ?><p class="notice notice-success" role="status">La categoría se guardó correctamente.</p><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><p class="notice notice-error" role="alert"><?= e($errorMessage) ?></p><?php endif; ?>

        <?php if ($canManage): ?>
            <h2>Agregar categoría</h2>
            <form class="admin-form" method="post" action="/categorias.php">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                <label>Nombre <input name="name" maxlength="100" required></label>
                <label>Descripción <input name="description" maxlength="255"></label>
                <label>Estado <select name="is_active"><option value="1">Activa</option><option value="0">Inactiva</option></select></label>
                <button type="submit">Agregar categoría</button>
            </form>
        <?php endif; ?>

        <h2>Catálogo</h2>
        <div class="table-scroll">
            <table class="tickets-table">
                <thead><tr><th>Nombre</th><th>Descripción</th><th>Estado</th><?php if ($canManage): ?><th>Acción</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <?php if ($canManage): ?>
                            <td>
                                <form id="category-<?= (int) $category['id'] ?>" method="post" action="/categorias.php">
                                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                    <label class="sr-only" for="category-name-<?= (int) $category['id'] ?>">Nombre</label>
                                    <input id="category-name-<?= (int) $category['id'] ?>" name="name" maxlength="100" value="<?= e($category['name']) ?>" required>
                                </form>
                            </td>
                            <td><label class="sr-only" for="category-description-<?= (int) $category['id'] ?>">Descripción</label><input id="category-description-<?= (int) $category['id'] ?>" form="category-<?= (int) $category['id'] ?>" name="description" maxlength="255" value="<?= e($category['description']) ?>"></td>
                            <td><select form="category-<?= (int) $category['id'] ?>" name="is_active"><option value="1" <?= (int) $category['is_active'] === 1 ? 'selected' : '' ?>>Activa</option><option value="0" <?= (int) $category['is_active'] === 0 ? 'selected' : '' ?>>Inactiva</option></select></td>
                            <td><button form="category-<?= (int) $category['id'] ?>" type="submit">Guardar</button></td>
                        <?php else: ?>
                            <td><?= e($category['name']) ?></td>
                            <td><?= e($category['description']) ?></td>
                            <td><?= (int) $category['is_active'] === 1 ? 'Activa' : 'Inactiva' ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($categories === []): ?><tr><td colspan="<?= $canManage ? 4 : 3 ?>">No hay categorías registradas.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canManage): ?><p>Las categorías se desactivan en lugar de eliminarse para conservar los tickets relacionados.</p><?php endif; ?>
    </section>
</main>
</body>
</html>
