<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/security.php';
initializeWebRequest();
require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/views/layouts/helpers.php';

$pdo = getDatabaseConnection();
$user = getAuthenticatedUser($pdo);

if ($user === null) {
    header('Location: /');
    exit;
}

$name = e($user['full_name']);
$email = e($user['email']);
$roleId = (int) $user['role_id'];
$roles = userRoleLabels();
$role = $roles[$roleId] ?? 'Usuario';
$canSeeCategories = in_array($roleId, [3, 4, 5], true);
$canSeeHistory = $canSeeCategories;
$canSeeAudit = in_array($roleId, [4, 5], true);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel principal | AnniDesk</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <main class="login-container">
        <section class="login-card dashboard-card">

            <header class="dashboard-header">
                <div class="dashboard-brand">
                    <div class="brand-icon" aria-hidden="true">A</div>
                    <div>
                        <h1>AnniDesk</h1>
                        <p class="subtitle">Centro de soporte técnico</p>
                    </div>
                </div>
            </header>

            <div class="dashboard-content">
                <section class="welcome-section">
                    <p class="eyebrow">Panel principal</p>
                    <h2>¡Bienvenida, <?= $name ?>!</h2>
                </section>

                <h2 class="section-title">Módulos del sistema</h2>

                <div class="module-grid">
                    
<a href="/gestion_tickets.php" class="module-card">
    <div class="module-icon" aria-hidden="true">▤</div>
    <div>
        <h3>Gestión de tickets</h3>
        <p><?= $roleId === 1
            ? 'Crea solicitudes y consulta tus tickets.'
            : ($roleId === 2
                ? 'Consulta y atiende los tickets asignados.'
                : 'Consulta los tickets según tus permisos.') ?></p>
        <span class="module-status">Abrir módulo</span>
    </div>
</a>

<?php if ($roleId === 4): ?>
    <a href="/usuarios.php" class="module-card">
        <div class="module-icon green" aria-hidden="true">♙</div>
        <div>
            <h3>Usuarios y roles</h3>
            <p>Administra cuentas, roles y accesos.</p>
            <span class="module-status">Administrar usuarios</span>
        </div>
    </a>
<?php endif; ?>

<?php if ($canSeeCategories): ?>
    <a href="/categorias.php" class="module-card">
        <div class="module-icon purple" aria-hidden="true">▦</div>
        <div>
            <h3>Categorías</h3>
            <p><?= $roleId === 4
                ? 'Crea, modifica y desactiva categorías.'
                : 'Consulta el catálogo en modo de solo lectura.' ?></p>
            <span class="module-status"><?= $roleId === 4 ? 'Administrar categorías' : 'Solo lectura' ?></span>
        </div>
    </a>
<?php endif; ?>

<?php if ($canSeeHistory): ?>
    <a href="/historial.php" class="module-card">
        <div class="module-icon orange" aria-hidden="true">☷</div>
        <div>
            <h3>Historial de tickets</h3>
            <p>Consulta eventos y cambios por ticket.</p>
            <span class="module-status">Abrir historial</span>
        </div>
    </a>
<?php endif; ?>

<?php if ($canSeeAudit): ?>
    <a href="/auditoria.php" class="module-card">
        <div class="module-icon orange" aria-hidden="true">▧</div>
        <div>
            <h3>Auditoría general</h3>
            <p>Consulta operaciones registradas en el sistema.</p>
            <span class="module-status">Solo lectura</span>
        </div>
    </a>
<?php endif; ?>
                </div>

                <section class="profile-card">
                    <div class="profile-avatar" aria-hidden="true">A</div>
                    <div class="profile-info">
                        <h3><?= $name ?></h3>
                        <p><?= $email ?></p>
                    </div>
                    <span class="role-badge">
                        <?= e($role) ?>
                    </span>
                </section>
            </div>

            <footer class="dashboard-footer">
                <form method="post" action="/logout.php">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrfToken()) ?>"
                    >
                    <button type="submit">Cerrar sesión</button>
                </form>
            </footer>

        </section>
    </main>
</body>
</html>
