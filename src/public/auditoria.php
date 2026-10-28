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
if (!in_array($user['role_id'], [4, 5], true)) {
    http_response_code(403);
    exit('Solo administración y auditoría pueden consultar el registro general.');
}

$actionLabels = [
    'auth.login_succeeded' => 'Inicio de sesión',
    'auth.logout' => 'Cierre de sesión',
    'ticket.created' => 'Ticket creado',
    'ticket.status_changed' => 'Estado de ticket actualizado',
    'ticket.agent_assigned' => 'Agente asignado',
    'ticket.comment_added' => 'Comentario agregado',
    'user.created' => 'Cuenta creada',
    'user.updated' => 'Cuenta actualizada',
    'user.updated_with_password' => 'Cuenta y contraseña actualizadas',
    'category.created' => 'Categoría creada',
    'category.updated' => 'Categoría actualizada',
];
$entityLabels = [
    'ticket' => 'Ticket',
    'user' => 'Cuenta',
    'category' => 'Categoría',
];
$statusLabels = ticketStatusLabels();
$priorityLabels = ticketPriorityLabels();
$roleLabels = userRoleLabels();
$activeLabels = [0 => 'Inactiva', 1 => 'Activa'];

$actionInput = $_GET['action'] ?? '';
$actorInput = $_GET['actor_id'] ?? '';
$entityInput = $_GET['entity_id'] ?? '';
if (
    !is_string($actionInput) ||
    ($actionInput !== '' && !array_key_exists($actionInput, $actionLabels)) ||
    !is_string($actorInput) ||
    ($actorInput !== '' && (!ctype_digit($actorInput) || (int) $actorInput < 1)) ||
    !is_string($entityInput) ||
    ($entityInput !== '' && (!ctype_digit($entityInput) || (int) $entityInput < 1))
) {
    http_response_code(400);
    exit('Los filtros de auditoría no son válidos.');
}

$filters = [];
$params = [];
foreach ([
    'action' => $actionInput,
    'actor_id' => $actorInput,
    'entity_id' => $entityInput,
] as $field => $value) {
    if ($value !== '') {
        $filters[] = "l.{$field} = :{$field}";
        $params[$field] = $field === 'action' ? $value : (int) $value;
    }
}
$where = $filters === [] ? '' : 'WHERE ' . implode(' AND ', $filters);

$pageInput = $_GET['page'] ?? '1';
$page = is_string($pageInput) && ctype_digit($pageInput)
    ? max(1, (int) $pageInput)
    : 1;
$pageSize = 25;
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs l {$where}");
$countStmt->execute($params);
$rowCount = (int) $countStmt->fetchColumn();
$pageCount = max(1, (int) ceil($rowCount / $pageSize));
$page = min($page, $pageCount);
$stmt = $pdo->prepare(
    "SELECT l.id, l.actor_id, u.full_name AS actor_name, l.action,
            l.entity_type, l.entity_id, l.details, l.ip_address, l.created_at
     FROM audit_logs l
     LEFT JOIN users u ON u.id = l.actor_id
     {$where}
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmt->bindValue(
        ':' . $key,
        $value,
        $key === 'action' ? PDO::PARAM_STR : PDO::PARAM_INT
    );
}
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$actors = $pdo->query(
    'SELECT DISTINCT u.id, u.full_name
     FROM audit_logs l
     JOIN users u ON u.id = l.actor_id
     ORDER BY u.full_name'
)->fetchAll(PDO::FETCH_ASSOC);
$todayCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM audit_logs
     WHERE created_at >= CURRENT_DATE'
)->fetchColumn();

function auditDetailValue(
    string $key,
    mixed $value,
    array $statusLabels,
    array $priorityLabels,
    array $roleLabels,
    array $activeLabels,
    array $actors
): string {
    if (in_array($key, ['status', 'old_status', 'new_status'], true)) {
        return $statusLabels[$value] ?? (string) $value;
    }
    if ($key === 'priority') {
        return $priorityLabels[$value] ?? (string) $value;
    }
    if (in_array($key, ['role_id', 'old_role_id', 'new_role_id'], true)) {
        return $roleLabels[(int) $value] ?? 'Rol desconocido';
    }
    if (in_array($key, ['is_active', 'active'], true)) {
        return $activeLabels[(int) $value] ?? (string) $value;
    }
    if (in_array($key, ['old_agent_id', 'new_agent_id', 'assigned_agent_id'], true)) {
        if ($value === null || (int) $value === 0) {
            return 'Sin asignar';
        }
        foreach ($actors as $actor) {
            if ((int) $actor['id'] === (int) $value) {
                return $actor['full_name'];
            }
        }
        return 'Agente #' . (int) $value;
    }
    if ($key === 'internal' || $key === 'password_changed') {
        return $value ? 'Sí' : 'No';
    }
    if ($value === null || $value === '') {
        return 'Sin dato';
    }

    return is_scalar($value)
        ? (string) $value
        : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function auditDetailLabel(string $key): string
{
    return [
        'name' => 'Nombre',
        'email' => 'Correo',
        'old_email' => 'Correo anterior',
        'new_email' => 'Correo nuevo',
        'role_id' => 'Rol',
        'old_role_id' => 'Rol anterior',
        'new_role_id' => 'Rol nuevo',
        'is_active' => 'Estado de cuenta',
        'active' => 'Estado',
        'old_status' => 'Estado anterior',
        'new_status' => 'Estado nuevo',
        'status' => 'Estado',
        'priority' => 'Prioridad',
        'old_agent_id' => 'Agente anterior',
        'new_agent_id' => 'Agente nuevo',
        'assigned_agent_id' => 'Agente asignado',
        'ticket_number' => 'Ticket',
        'internal' => 'Nota interna',
        'password_changed' => 'Contraseña modificada',
    ][$key] ?? ucfirst(str_replace('_', ' ', $key));
}

function auditDetails(
    ?string $json,
    array $statusLabels,
    array $priorityLabels,
    array $roleLabels,
    array $activeLabels,
    array $actors
): array {
    if ($json === null || $json === '') {
        return [];
    }
    $details = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($details)) {
        return [];
    }

    $formatted = [];
    foreach ($details as $key => $value) {
        if (in_array($key, ['before', 'after'], true) && is_array($value)) {
            foreach ($value as $field => $fieldValue) {
                $formatted[] = [
                    'label' => ($key === 'before' ? 'Antes · ' : 'Después · ')
                        . auditDetailLabel((string) $field),
                    'value' => auditDetailValue(
                        (string) $field,
                        $fieldValue,
                        $statusLabels,
                        $priorityLabels,
                        $roleLabels,
                        $activeLabels,
                        $actors
                    ),
                ];
            }
            continue;
        }
        $formatted[] = [
            'label' => auditDetailLabel((string) $key),
            'value' => auditDetailValue(
                (string) $key,
                $value,
                $statusLabels,
                $priorityLabels,
                $roleLabels,
                $activeLabels,
                $actors
            ),
        ];
    }

    usort($formatted, static function (array $left, array $right): int {
        $rank = static function (string $label): int {
            if (
                str_starts_with($label, 'Antes ·') ||
                str_ends_with($label, 'anterior')
            ) {
                return 0;
            }
            if (
                str_starts_with($label, 'Después ·') ||
                str_ends_with($label, 'nuevo')
            ) {
                return 1;
            }

            return 2;
        };

        return $rank($left['label']) <=> $rank($right['label']);
    });

    return $formatted;
}

$query = array_filter([
    'action' => $actionInput,
    'actor_id' => $actorInput,
    'entity_id' => $entityInput,
], static fn (string $value): bool => $value !== '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auditoría | AnniDesk</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php
$currentPage = '/auditoria.php';
require __DIR__ . '/../app/views/layouts/navigation.php';
?>
<main class="tickets-wrap audit-page">
    <header class="tickets-toolbar">
        <div>
            <h1>Auditoría general</h1>
            <p>Actividad registrada en tickets, cuentas y categorías.</p>
        </div>
        <div class="audit-counts">
            <span><strong><?= $rowCount ?></strong> eventos encontrados</span>
            <span><strong><?= $todayCount ?></strong> hoy</span>
        </div>
    </header>

    <section class="tickets-table-wrap">
        <form class="admin-form audit-filters" method="get" action="/auditoria.php">
            <label>Tipo de actividad
                <select name="action">
                    <option value="">Todas</option>
                    <?php foreach ($actionLabels as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $actionInput === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Realizado por
                <select name="actor_id">
                    <option value="">Cualquier usuario</option>
                    <?php foreach ($actors as $actor): ?>
                        <option value="<?= (int) $actor['id'] ?>" <?= $actorInput === (string) $actor['id'] ? 'selected' : '' ?>>
                            <?= e($actor['full_name']) ?> (#<?= (int) $actor['id'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>ID del registro
                <input type="number" name="entity_id" min="1" value="<?= e($entityInput) ?>" placeholder="Ej. 12">
            </label>
            <button type="submit">Aplicar filtros</button>
            <?php if ($query !== []): ?><a href="/auditoria.php">Limpiar</a><?php endif; ?>
        </form>

        <?php if ($logs === []): ?>
            <div class="empty-state audit-empty">
                <h2>No hay actividad que coincida</h2>
                <p>Prueba con otros filtros o limpia la búsqueda para revisar todos los eventos.</p>
                <?php if ($query !== []): ?><a class="action-link" href="/auditoria.php">Ver toda la actividad</a><?php endif; ?>
            </div>
        <?php else: ?>
            <ol class="audit-feed">
                <?php foreach ($logs as $log): ?>
                    <?php
                    $details = auditDetails(
                        $log['details'],
                        $statusLabels,
                        $priorityLabels,
                        $roleLabels,
                        $activeLabels,
                        $actors
                    );
                    $entityType = $log['entity_type'];
                    $entityName = $entityLabels[$entityType] ?? ucfirst($entityType);
                    $entityId = $log['entity_id'] === null ? null : (int) $log['entity_id'];
                    $entityHref = match ($entityType) {
                        'ticket' => $entityId === null ? null : '/ticket.php?id=' . $entityId,
                        'category' => '/categorias.php',
                        'user' => $user['role_id'] === 4 ? '/usuarios.php' : null,
                        default => null,
                    };
                    ?>
                    <li class="audit-event">
                        <div class="audit-event-marker" aria-hidden="true"></div>
                        <article>
                            <header class="audit-event-header">
                                <div>
                                    <h2><?= e($actionLabels[$log['action']] ?? $log['action']) ?></h2>
                                    <p>
                                        <?= e($log['actor_name'] ?? 'Sistema') ?>
                                        <?php if ($log['actor_id'] !== null): ?> · usuario #<?= (int) $log['actor_id'] ?><?php endif; ?>
                                        · <?= e($log['created_at']) ?>
                                    </p>
                                </div>
                                <?php if ($user['role_id'] === 4 && $log['ip_address'] !== null): ?>
                                    <span class="audit-ip">IP <?= e($log['ip_address']) ?></span>
                                <?php endif; ?>
                            </header>
                            <p class="audit-entity">
                                Registro:
                                <?php if ($entityHref !== null): ?>
                                    <a href="<?= e($entityHref) ?>"><?= e($entityName) ?><?= $entityId === null ? '' : ' #' . $entityId ?></a>
                                <?php else: ?>
                                    <?= e($entityName) ?><?= $entityId === null ? '' : ' #' . $entityId ?>
                                <?php endif; ?>
                            </p>
                            <?php if ($details !== []): ?>
                                <dl class="audit-details">
                                    <?php foreach ($details as $detail): ?>
                                        <div>
                                            <dt><?= e($detail['label']) ?></dt>
                                            <dd><?= e($detail['value']) ?></dd>
                                        </div>
                                    <?php endforeach; ?>
                                </dl>
                            <?php endif; ?>
                        </article>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <?php if ($pageCount > 1): ?>
            <?php $paginationQuery = http_build_query($query); ?>
            <nav aria-label="Paginación">
                <?php if ($page > 1): ?><a href="?<?= e($paginationQuery) ?><?= $paginationQuery === '' ? '' : '&' ?>page=<?= $page - 1 ?>">Anterior</a><?php endif; ?>
                Página <?= $page ?> de <?= $pageCount ?>
                <?php if ($page < $pageCount): ?><a href="?<?= e($paginationQuery) ?><?= $paginationQuery === '' ? '' : '&' ?>page=<?= $page + 1 ?>">Siguiente</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
