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

$idInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['ticket_id'] ?? '')
    : ($_GET['id'] ?? '');
if (!is_string($idInput) || !ctype_digit($idInput) || (int) $idInput < 1) {
    http_response_code(400);
    exit('El número de ticket no es válido.');
}
$ticketId = (int) $idInput;
$ticket = findAccessibleTicket($pdo, $ticketId, $user);
if ($ticket === null) {
    http_response_code(404);
    exit('No se encontró el ticket solicitado.');
}

$canComment = in_array($user['role_id'], [1, 2, 3, 4], true);
$canViewInternal = in_array($user['role_id'], [2, 3, 4], true);
$errorMessage = '';
$success = ($_GET['commented'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitud no válida. Recarga la página e inténtalo de nuevo.');
    }
    if (!$canComment) {
        http_response_code(403);
        exit('Tu perfil tiene acceso de solo lectura.');
    }

    $body = $_POST['body'] ?? '';
    $internalInput = $_POST['is_internal'] ?? '0';
    if (
        !is_string($body) ||
        !in_array($internalInput, ['0', '1'], true) ||
        ($internalInput === '1' && !$canViewInternal)
    ) {
        $errorMessage = 'Los datos enviados no son válidos.';
    } else {
        try {
            addTicketComment(
                $pdo,
                $ticketId,
                $user['id'],
                $body,
                $internalInput === '1',
                requestIpAddress()
            );
            header('Location: /ticket.php?id=' . $ticketId . '&commented=1');
            exit;
        } catch (InvalidArgumentException $exception) {
            $errorMessage = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Error al agregar comentario: ' . $exception->getMessage());
            $errorMessage = 'No se pudo agregar el comentario.';
        }
    }
}

$commentStmt = $pdo->prepare(
    'SELECT c.id, c.author_id, u.full_name AS author_name, c.body,
            c.is_internal, c.created_at
     FROM comments c
     JOIN users u ON u.id = c.author_id
     WHERE c.ticket_id = :ticket_id
       AND (c.is_internal = 0 OR :can_view_internal = 1)
     ORDER BY c.created_at, c.id'
);
$commentStmt->execute([
    'ticket_id' => $ticketId,
    'can_view_internal' => $canViewInternal,
]);
$comments = $commentStmt->fetchAll(PDO::FETCH_ASSOC);
$statuses = ticketStatusLabels();
$priorities = ticketPriorityLabels();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($ticket['ticket_number']) ?> | AnniDesk</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php
$currentPage = '/gestion_tickets.php';
require __DIR__ . '/../app/views/layouts/navigation.php';
?>
<main class="tickets-wrap">
    <header class="tickets-toolbar">
        <div><h1><?= e($ticket['ticket_number']) ?> · <?= e($ticket['title']) ?></h1><p>Detalle y conversación de la solicitud</p></div>
    </header>
    <section class="tickets-table-wrap ticket-detail">
        <?php if ($success): ?><p class="notice notice-success" role="status">Comentario agregado correctamente.</p><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><p class="notice notice-error" role="alert"><?= e($errorMessage) ?></p><?php endif; ?>
        <dl class="ticket-summary">
            <dt>Estado</dt><dd><?= e($statuses[$ticket['status']] ?? $ticket['status']) ?></dd>
            <dt>Prioridad</dt><dd><?= e($priorities[$ticket['priority']] ?? $ticket['priority']) ?></dd>
            <dt>Solicitante</dt><dd><?= e($ticket['requester_name']) ?></dd>
            <dt>Agente asignado</dt><dd><?= e($ticket['agent_name'] ?? 'Sin asignar') ?></dd>
            <dt>Categoría</dt><dd><?= e($ticket['category_name'] ?? 'Sin categoría') ?></dd>
            <dt>Creado</dt><dd><?= e($ticket['created_at']) ?></dd>
        </dl>
        <h2>Descripción</h2>
        <p class="ticket-description"><?= e($ticket['description']) ?></p>

        <h2>Conversación</h2>
        <?php if ($comments === []): ?><p>Aún no hay comentarios.</p><?php endif; ?>
        <?php foreach ($comments as $comment): ?>
            <article class="ticket-comment<?= (int) $comment['is_internal'] === 1 ? ' internal' : '' ?>">
                <header><strong><?= e($comment['author_name']) ?></strong>
                    <time datetime="<?= e($comment['created_at']) ?>"><?= e($comment['created_at']) ?></time>
                    <?php if ((int) $comment['is_internal'] === 1): ?><span class="role-badge">Nota interna</span><?php endif; ?>
                </header>
                <p><?= e($comment['body']) ?></p>
            </article>
        <?php endforeach; ?>

        <?php if ($canComment && $ticket['status'] !== 'closed'): ?>
            <h2>Agregar comentario</h2>
            <form class="admin-form comment-form" method="post" action="/ticket.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="ticket_id" value="<?= $ticketId ?>">
                <label>Mensaje <textarea name="body" maxlength="5000" rows="5" required></textarea></label>
                <?php if ($canViewInternal): ?>
                    <label class="checkbox-label"><input type="checkbox" name="is_internal" value="1"> Nota interna (visible solo al equipo de soporte)</label>
                <?php endif; ?>
                <button type="submit">Enviar comentario</button>
            </form>
        <?php elseif ($ticket['status'] === 'closed'): ?>
            <p>El ticket está cerrado y no acepta comentarios.</p>
        <?php else: ?>
            <p>Tu perfil tiene acceso de solo lectura a esta solicitud.</p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
