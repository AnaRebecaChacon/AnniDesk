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
if (!in_array($user['role_id'], [3, 4, 5], true)) {
    http_response_code(403);
    exit('No tienes permiso para consultar el historial.');
}

$ticketInput = $_GET['ticket_id'] ?? '';
if (!is_string($ticketInput) || ($ticketInput !== '' && !ctype_digit($ticketInput))) {
    http_response_code(400);
    exit('El filtro de ticket no es válido.');
}
$ticketId = $ticketInput === '' ? null : (int) $ticketInput;
$pageInput = $_GET['page'] ?? '1';
$page = is_string($pageInput) && ctype_digit($pageInput) ? max(1, (int) $pageInput) : 1;
$pageSize = 50;
$where = $ticketId === null ? '' : 'WHERE h.ticket_id = :ticket_id';
$params = $ticketId === null ? [] : ['ticket_id' => $ticketId];
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ticket_history h {$where}");
$countStmt->execute($params);
$rowCount = (int) $countStmt->fetchColumn();
$pageCount = max(1, (int) ceil($rowCount / $pageSize));
$page = min($page, $pageCount);
$stmt = $pdo->prepare(
    "SELECT h.id, h.ticket_id, t.ticket_number, h.actor_id,
            u.full_name AS actor_name, h.event_type, h.old_value,
            h.new_value, h.created_at
     FROM ticket_history h
     JOIN tickets t ON t.id = h.ticket_id
     LEFT JOIN users u ON u.id = h.actor_id
     {$where}
     ORDER BY h.created_at DESC, h.id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
}
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$stmt->execute();
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

function historyValues(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }

    $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($values)) {
        return [];
    }

    $fieldLabels = [
        'status' => 'Estado',
        'priority' => 'Prioridad',
        'assigned_agent_id' => 'Agente asignado',
        'internal' => 'Comentario',
    ];
    $valueLabels = [
        'status' => ticketStatusLabels(),
        'priority' => ticketPriorityLabels(),
        'internal' => [
            true => 'Nota interna',
            false => 'Comentario público',
        ],
    ];
    $formatted = [];

    foreach ($values as $field => $value) {
        $label = $fieldLabels[$field] ?? ucfirst(str_replace('_', ' ', (string) $field));
        if ($field === 'assigned_agent_id') {
            $displayValue = $value === null ? 'Sin asignar' : 'Agente #' . $value;
        } elseif (isset($valueLabels[$field]) && is_string($value)) {
            $displayValue = $valueLabels[$field][$value] ?? $value;
        } elseif ($field === 'internal' && is_bool($value)) {
            $displayValue = $valueLabels[$field][$value];
        } elseif ($value === null) {
            $displayValue = 'Sin valor';
        } elseif (is_scalar($value)) {
            $displayValue = (string) $value;
        } else {
            $displayValue = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $formatted[] = ['label' => $label, 'value' => $displayValue];
    }

    return $formatted;
}

$eventLabels = [
    'ticket_created' => 'Ticket creado',
    'status_changed' => 'Cambio de estado',
    'agent_assigned' => 'Asignación actualizada',
    'comment_added' => 'Comentario agregado',
    'internal_comment_added' => 'Nota interna agregada',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historial de tickets | AnniDesk</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php
$currentPage = '/historial.php';
require __DIR__ . '/../app/views/layouts/navigation.php';
?>
<main class="tickets-wrap">
    <header class="tickets-toolbar">
        <div><h1>Historial de tickets</h1><p>Registro de cambios de estado, asignaciones y comentarios.</p></div>
    </header>
    <section class="tickets-table-wrap">
        <form class="admin-form" method="get" action="/historial.php">
            <label>Filtrar por ID de ticket <input type="number" name="ticket_id" min="1" value="<?= e($ticketInput) ?>"></label>
            <button type="submit">Filtrar</button>
            <?php if ($ticketId !== null): ?><a href="/historial.php">Quitar filtro</a><?php endif; ?>
        </form>
        <div class="table-scroll"><table class="tickets-table">
            <thead><tr><th>Fecha</th><th>Ticket</th><th>Evento</th><th>Actor</th><th>Antes</th><th>Después</th></tr></thead>
            <tbody>
            <?php foreach ($history as $event): ?>
                <tr>
                    <td><?= e($event['created_at']) ?></td>
                    <td><a href="/ticket.php?id=<?= (int) $event['ticket_id'] ?>"><?= e($event['ticket_number']) ?></a></td>
                    <td><?= e($eventLabels[$event['event_type']] ?? $event['event_type']) ?></td>
                    <td><?= e($event['actor_name'] ?? 'Sistema') ?></td>
                    <td>
                        <?php $oldValues = historyValues($event['old_value']); ?>
                        <?php if ($oldValues === []): ?>
                            <span class="history-empty">—</span>
                        <?php else: ?>
                            <ul class="history-values">
                                <?php foreach ($oldValues as $item): ?>
                                    <li><span><?= e($item['label']) ?>:</span> <?= e($item['value']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $newValues = historyValues($event['new_value']); ?>
                        <?php if ($newValues === []): ?>
                            <span class="history-empty">—</span>
                        <?php else: ?>
                            <ul class="history-values">
                                <?php foreach ($newValues as $item): ?>
                                    <li><span><?= e($item['label']) ?>:</span> <?= e($item['value']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($history === []): ?><tr><td colspan="6">No hay eventos para mostrar.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <?php if ($pageCount > 1): ?><nav aria-label="Paginación"><?php if ($page > 1): ?><a href="?<?= $ticketId === null ? '' : 'ticket_id=' . $ticketId . '&' ?>page=<?= $page - 1 ?>">Anterior</a><?php endif; ?> Página <?= $page ?> de <?= $pageCount ?> <?php if ($page < $pageCount): ?><a href="?<?= $ticketId === null ? '' : 'ticket_id=' . $ticketId . '&' ?>page=<?= $page + 1 ?>">Siguiente</a><?php endif; ?></nav><?php endif; ?>
    </section>
</main>
</body>
</html>
