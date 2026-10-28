<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/security.php';
initializeWebRequest();
require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/controllers/TicketController.php';
require_once __DIR__ . '/../app/views/layouts/helpers.php';

$pdo = getDatabaseConnection();
$user = getAuthenticatedUser($pdo);

if ($user === null) {
    header('Location: /');
    exit;
}

csrfToken();

// Roles: 2 = Agente, 3 = Supervisor, 4 = Administrador.
$canUpdateStatus = in_array(
    $user['role_id'],
    [2, 3, 4],
    true
);
$canAssignTickets = in_array($user['role_id'], [3, 4], true);

$errorMessage = '';
$successAction = $_GET['updated'] ?? '';

$statuses = ticketStatusLabels();
$priorities = ticketPriorityLabels();

// Procesar la actualización del estado.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitud no válida. Recarga la página e inténtalo de nuevo.');
    }

    $action = $_POST['action'] ?? 'status';
    $ticketIdInput = $_POST['ticket_id'] ?? '';

    if (
        !is_string($ticketIdInput) ||
        !ctype_digit($ticketIdInput) ||
        (int) $ticketIdInput < 1
    ) {
        $errorMessage = 'Los datos enviados no son válidos.';
    } elseif ($action === 'assign') {
        if (!$canAssignTickets) {
            http_response_code(403);
            exit('No tienes permisos para asignar tickets.');
        }

        $agentInput = $_POST['agent_id'] ?? '';

        if (
            !is_string($agentInput) ||
            ($agentInput !== '' &&
                (!ctype_digit($agentInput) || (int) $agentInput < 1))
        ) {
            $errorMessage = 'El agente seleccionado no es válido.';
        } else {
            try {
                assignTicket(
                    (int) $ticketIdInput,
                    $agentInput === '' ? null : (int) $agentInput,
                    $user['id'],
                    requestIpAddress()
                );

                header('Location: /gestion_tickets.php?updated=assigned');
                exit;
            } catch (InvalidArgumentException $exception) {
                $errorMessage = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log(
                    'Error al asignar el ticket: ' . $exception->getMessage()
                );
                $errorMessage =
                    'No se pudo asignar el ticket. Inténtalo nuevamente.';
            }
        }
    } elseif ($action !== 'status') {
        $errorMessage = 'La operación solicitada no es válida.';
    } else {
        if (!$canUpdateStatus) {
            http_response_code(403);
            exit('No tienes permisos para cambiar el estado de los tickets.');
        }

        $newStatus = $_POST['status'] ?? '';
        if (!is_string($newStatus) || !array_key_exists($newStatus, $statuses)) {
            $errorMessage = 'Los datos enviados no son válidos.';
        } else {
            try {
                updateTicketStatus(
                    (int) $ticketIdInput,
                    $user['id'],
                    $newStatus,
                    requestIpAddress()
                );

                header('Location: /gestion_tickets.php?updated=1');
                exit;
            } catch (InvalidArgumentException $exception) {
                $errorMessage = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log(
                    'Error al actualizar el estado del ticket: ' .
                    $exception->getMessage()
                );
                $errorMessage =
                    'No se pudo actualizar el ticket. Inténtalo nuevamente.';
            }
        }
    }
}

$agents = [];
if ($canAssignTickets) {
    $agents = $pdo->query(
        'SELECT id, full_name
         FROM users
         WHERE role_id = 2 AND is_active = 1
         ORDER BY full_name'
    )->fetchAll(PDO::FETCH_ASSOC);
}

// Cada perfil consulta únicamente los tickets que le corresponden.
$pageInput = $_GET['page'] ?? '1';
$page = is_string($pageInput) && ctype_digit($pageInput)
    ? max(1, (int) $pageInput)
    : 1;
$pageSize = 50;
$where = '';
$parameters = [];

if ($user['role_id'] === 1) {
    $where = 'WHERE t.requester_id = :user_id';
    $parameters['user_id'] = $user['id'];
} elseif ($user['role_id'] === 2) {
    $where = 'WHERE t.assigned_agent_id = :user_id';
    $parameters['user_id'] = $user['id'];
} elseif (!in_array($user['role_id'], [3, 4, 5], true)) {
    $where = 'WHERE t.id = 0';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t {$where}");
$countStmt->execute($parameters);
$ticketCount = (int) $countStmt->fetchColumn();
$pageCount = max(1, (int) ceil($ticketCount / $pageSize));
$page = min($page, $pageCount);
$offset = ($page - 1) * $pageSize;

$sql = "
    SELECT
        t.id,
        t.ticket_number,
        t.assigned_agent_id,
        t.title,
        t.status,
        t.priority,
        t.created_at,
        c.name AS category_name,
        u.full_name AS requester_name,
        a.full_name AS agent_name
    FROM tickets t
    LEFT JOIN categories c ON c.id = t.category_id
    LEFT JOIN users u ON u.id = t.requester_id
    LEFT JOIN users a ON a.id = t.assigned_agent_id
    {$where}
    ORDER BY t.created_at DESC
    LIMIT :page_size OFFSET :offset
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($parameters as $name => $value) {
    $stmt->bindValue(':' . $name, $value, PDO::PARAM_INT);
}
$stmt->execute();
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Gestión de tickets | AnniDesk</title>

    <link rel="stylesheet" href="/assets/css/style.css">

</head>

<body>
    <?php
    $currentPage = '/gestion_tickets.php';
    require __DIR__ . '/../app/views/layouts/navigation.php';
    ?>
    <main class="tickets-wrap">

        <div class="tickets-toolbar">
            <div>
                <h1>Gestión de tickets</h1>
                <p>Solicitudes registradas en el sistema.</p>
            </div>

            <div>
                <?php if ($user['role_id'] === 1): ?>
                    <a class="action-link" href="/tickets.php">
                        + Crear ticket
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="tickets-table-wrap">

            <?php if (in_array($successAction, ['1', 'assigned'], true)): ?>
                <div class="notice notice-success" role="status">
                    <?= $successAction === 'assigned'
                        ? 'La asignación del ticket se actualizó correctamente.'
                        : 'El estado del ticket se actualizó correctamente.' ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="notice notice-error" role="alert">
                    <?= e($errorMessage) ?>
                </div>
            <?php endif; ?>

            <?php if (empty($tickets)): ?>

                <div class="empty-state">
                    <h2>Aún no hay tickets</h2>
                    <p>
                        Cuando registres una solicitud, aparecerá aquí.
                    </p>
                </div>

            <?php else: ?>

                <div class="table-scroll">
                <table class="tickets-table">
                    <thead>
                        <tr>
                            <th>Número</th>
                            <th>Título</th>
                            <th>Solicitante</th>
                            <th>Categoría</th>
                            <th>Prioridad</th>
                            <th>Estado</th>
                            <th>Agente asignado</th>
                            <th>Creado</th>
                            <th>Actualizar estado</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($tickets as $ticket): ?>
                        <tr>
                            <td>
                                <a href="/ticket.php?id=<?= (int) $ticket['id'] ?>">
                                    <?= e($ticket['ticket_number']) ?>
                                </a>
                            </td>

                            <td>
                                <?= e($ticket['title']) ?>
                            </td>

                            <td>
                                <?= e($ticket['requester_name']) ?>
                            </td>

                            <td>
                                <?= e(
                                    $ticket['category_name']
                                    ?? 'Sin categoría'
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $priorities[$ticket['priority']]
                                    ?? $ticket['priority']
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $statuses[$ticket['status']]
                                    ?? $ticket['status']
                                ) ?>
                            </td>

                            <td>
                                <?php if ($canAssignTickets): ?>
                                    <form
                                        class="status-form"
                                        method="POST"
                                        action="/gestion_tickets.php"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e(csrfToken()) ?>"
                                        >
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="assign"
                                        >
                                        <input
                                            type="hidden"
                                            name="ticket_id"
                                            value="<?= (int) $ticket['id'] ?>"
                                        >
                                        <select
                                            class="status-select"
                                            name="agent_id"
                                            aria-label="Agente asignado al ticket <?= e($ticket['ticket_number']) ?>"
                                        >
                                            <option value="">Sin asignar</option>
                                            <?php foreach ($agents as $agent): ?>
                                                <option
                                                    value="<?= (int) $agent['id'] ?>"
                                                    <?= (int) $ticket['assigned_agent_id'] === (int) $agent['id']
                                                        ? 'selected'
                                                        : '' ?>
                                                >
                                                    <?= e($agent['full_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="status-button" type="submit">
                                            Asignar
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <?= e(
                                        $ticket['agent_name']
                                        ?? 'Sin asignar'
                                    ) ?>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= e($ticket['created_at']) ?>
                            </td>

                            <td>
                                <?php if ($canUpdateStatus): ?>

                                    <form
                                        class="status-form"
                                        method="POST"
                                        action="/gestion_tickets.php"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e(csrfToken()) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="ticket_id"
                                            value="<?= (int) $ticket['id'] ?>"
                                        >

                                        <select
                                            class="status-select"
                                            name="status"
                                            aria-label="Nuevo estado del ticket <?= e($ticket['ticket_number']) ?>"
                                            required
                                        >
                                            <?php foreach ($statuses as $value => $label): ?>
                                                <option
                                                    value="<?= e($value) ?>"
                                                    <?= $ticket['status'] === $value
                                                        ? 'selected'
                                                        : '' ?>
                                                >
                                                    <?= e($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button
                                            class="status-button"
                                            type="submit"
                                        >
                                            Guardar
                                        </button>
                                    </form>

                                <?php else: ?>

                                    <span class="no-permission">
                                        Solo lectura
                                    </span>

                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

            <?php endif; ?>

            <?php if ($pageCount > 1): ?>
                <nav aria-label="Paginación de tickets">
                    <?php if ($page > 1): ?>
                        <a class="action-link" href="?page=<?= $page - 1 ?>">
                            Anterior
                        </a>
                    <?php endif; ?>
                    <span>Página <?= $page ?> de <?= $pageCount ?></span>
                    <?php if ($page < $pageCount): ?>
                        <a class="action-link" href="?page=<?= $page + 1 ?>">
                            Siguiente
                        </a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>

        </div>
    </main>
</body>
</html>
