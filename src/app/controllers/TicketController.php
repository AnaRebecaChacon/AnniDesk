<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AuditService.php';

/**
 * Crea un ticket para el usuario autenticado.
 * Devuelve el ID del ticket creado.
 */
function createTicket(
    int $requesterId,
    string $title,
    string $description,
    ?int $categoryId,
    string $priority
): int {
    $title = trim($title);
    $description = trim($description);

    $allowedPriorities = ['low', 'medium', 'high', 'urgent'];

    if ($requesterId < 1) {
        throw new InvalidArgumentException('Usuario no válido.');
    }

    if ($title === '' || mb_strlen($title) > 200) {
        throw new InvalidArgumentException(
            'El título es obligatorio y no debe superar los 200 caracteres.'
        );
    }

    if ($description === '' || mb_strlen($description) > 10000) {
        throw new InvalidArgumentException(
            'La descripción es obligatoria y no debe superar los 10 000 caracteres.'
        );
    }

    if (!in_array($priority, $allowedPriorities, true)) {
        throw new InvalidArgumentException('La prioridad no es válida.');
    }

    $pdo = getDatabaseConnection();

    // Confirmar que el usuario exista y esté activo.
    $userStmt = $pdo->prepare(
        'SELECT role_id
         FROM users
         WHERE id = :id AND is_active = 1'
    );
    $userStmt->execute(['id' => $requesterId]);
    $user = $userStmt->fetch();

    if (!$user) {
        throw new RuntimeException('El usuario no existe o está inactivo.');
    }

    if ((int) $user['role_id'] !== 1) {
        throw new RuntimeException(
            'Solo el perfil solicitante puede crear tickets.'
        );
    }

    // Verificar la categoría cuando se ha seleccionado una.
    if ($categoryId !== null) {
        $categoryStmt = $pdo->prepare(
            'SELECT id FROM categories WHERE id = :id AND is_active = 1'
        );
        $categoryStmt->execute(['id' => $categoryId]);

        if (!$categoryStmt->fetch()) {
            throw new InvalidArgumentException(
                'La categoría seleccionada no existe o está inactiva.'
            );
        }
    }

    $ticketNumber = 'HD-' . date('Ymd') . '-' .
        strtoupper(bin2hex(random_bytes(8)));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO tickets
                (ticket_number, requester_id, category_id, title, description, priority)
             VALUES
                (:ticket_number, :requester_id, :category_id, :title, :description, :priority)'
        );
        $stmt->execute([
            'ticket_number' => $ticketNumber,
            'requester_id' => $requesterId,
            'category_id' => $categoryId,
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
        ]);
        $ticketId = (int) $pdo->lastInsertId();

        recordTicketChange(
            $pdo,
            $ticketId,
            $requesterId,
            'ticket_created',
            [],
            ['status' => 'open', 'priority' => $priority],
            'ticket.created',
            ['ticket_number' => $ticketNumber],
            null
        );

        $pdo->commit();
        return $ticketId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function recordTicketChange(
    PDO $pdo,
    int $ticketId,
    int $actorId,
    string $eventType,
    array $oldValue,
    array $newValue,
    string $action,
    array $details,
    ?string $ipAddress
): void {
    $historyStmt = $pdo->prepare(
        'INSERT INTO ticket_history
            (ticket_id, actor_id, event_type, old_value, new_value)
         VALUES
            (:ticket_id, :actor_id, :event_type, :old_value, :new_value)'
    );
    $historyStmt->execute([
        'ticket_id' => $ticketId,
        'actor_id' => $actorId,
        'event_type' => $eventType,
        'old_value' => json_encode(
            $oldValue,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ),
        'new_value' => json_encode(
            $newValue,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ),
    ]);

    recordAuditEntry(
        $pdo,
        $actorId,
        $action,
        'ticket',
        $ticketId,
        $details,
        $ipAddress
    );
}

function findAccessibleTicket(PDO $pdo, int $ticketId, array $user): ?array
{
    $where = 't.id = :ticket_id';
    $params = ['ticket_id' => $ticketId];

    if ($user['role_id'] === 1) {
        $where .= ' AND t.requester_id = :user_id';
        $params['user_id'] = $user['id'];
    } elseif ($user['role_id'] === 2) {
        $where .= ' AND t.assigned_agent_id = :user_id';
        $params['user_id'] = $user['id'];
    } elseif (!in_array($user['role_id'], [3, 4, 5], true)) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT t.id, t.ticket_number, t.requester_id, t.assigned_agent_id,
                t.title, t.description, t.status, t.priority, t.created_at,
                t.updated_at, t.resolved_at,
                c.name AS category_name, requester.full_name AS requester_name,
                agent.full_name AS agent_name
         FROM tickets t
         LEFT JOIN categories c ON c.id = t.category_id
         JOIN users requester ON requester.id = t.requester_id
         LEFT JOIN users agent ON agent.id = t.assigned_agent_id
         WHERE {$where}
         LIMIT 1"
    );
    $stmt->execute($params);
    $ticket = $stmt->fetch();

    return $ticket ?: null;
}

function addTicketComment(
    PDO $pdo,
    int $ticketId,
    int $actorId,
    string $body,
    bool $isInternal,
    ?string $ipAddress = null
): void {
    $body = trim($body);
    if ($ticketId < 1 || $actorId < 1) {
        throw new InvalidArgumentException('El ticket o usuario no es válido.');
    }
    if ($body === '' || mb_strlen($body) > 5000) {
        throw new InvalidArgumentException(
            'El comentario es obligatorio y no debe superar los 5 000 caracteres.'
        );
    }

    $pdo->beginTransaction();
    try {
        $actorStmt = $pdo->prepare(
            'SELECT role_id
             FROM users
             WHERE id = :id AND is_active = 1
             FOR UPDATE'
        );
        $actorStmt->execute(['id' => $actorId]);
        $actor = $actorStmt->fetch();
        if (!$actor) {
            throw new RuntimeException('La cuenta no existe o está inactiva.');
        }

        $ticketStmt = $pdo->prepare(
            'SELECT id, ticket_number, requester_id, assigned_agent_id, status
             FROM tickets WHERE id = :id FOR UPDATE'
        );
        $ticketStmt->execute(['id' => $ticketId]);
        $ticket = $ticketStmt->fetch();
        if (!$ticket) {
            throw new RuntimeException('El ticket solicitado no existe.');
        }

        $roleId = (int) $actor['role_id'];
        $canComment = match ($roleId) {
            1 => (int) $ticket['requester_id'] === $actorId && !$isInternal,
            2 => (int) $ticket['assigned_agent_id'] === $actorId,
            3, 4 => true,
            default => false,
        };
        if (!$canComment) {
            throw new RuntimeException(
                'No tienes permiso para comentar en este ticket.'
            );
        }
        if ($ticket['status'] === 'closed') {
            throw new InvalidArgumentException(
                'No se pueden agregar comentarios a un ticket cerrado.'
            );
        }

        $stmt = $pdo->prepare(
            'INSERT INTO comments (ticket_id, author_id, body, is_internal)
             VALUES (:ticket_id, :author_id, :body, :is_internal)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'author_id' => $actorId,
            'body' => $body,
            'is_internal' => (int) $isInternal,
        ]);

        recordTicketChange(
            $pdo,
            $ticketId,
            $actorId,
            $isInternal ? 'internal_comment_added' : 'comment_added',
            [],
            ['internal' => $isInternal],
            'ticket.comment_added',
            [
                'ticket_number' => $ticket['ticket_number'],
                'internal' => $isInternal,
            ],
            $ipAddress
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Asigna o desasigna un ticket y registra el cambio en el historial.
 */
function assignTicket(
    int $ticketId,
    ?int $agentId,
    int $actorId,
    ?string $ipAddress = null
): void {
    if ($ticketId < 1 || $actorId < 1 || ($agentId !== null && $agentId < 1)) {
        throw new InvalidArgumentException('El ticket o usuario no es válido.');
    }

    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();

    try {
        $actorStmt = $pdo->prepare(
            'SELECT role_id
             FROM users
             WHERE id = :id AND is_active = 1
             FOR UPDATE'
        );
        $actorStmt->execute(['id' => $actorId]);
        $actor = $actorStmt->fetch();

        if (!$actor || !in_array((int) $actor['role_id'], [3, 4], true)) {
            throw new RuntimeException(
                'No tienes permisos para asignar tickets.'
            );
        }

        $ticketStmt = $pdo->prepare(
            'SELECT id, ticket_number, assigned_agent_id
             FROM tickets
             WHERE id = :id
             FOR UPDATE'
        );
        $ticketStmt->execute(['id' => $ticketId]);
        $ticket = $ticketStmt->fetch();

        if (!$ticket) {
            throw new RuntimeException('El ticket solicitado no existe.');
        }

        $newAgentId = $agentId;

        if ($newAgentId !== null) {
            $agentStmt = $pdo->prepare(
                'SELECT id
                 FROM users
                 WHERE id = :id AND role_id = 2 AND is_active = 1
                 FOR UPDATE'
            );
            $agentStmt->execute(['id' => $newAgentId]);

            if (!$agentStmt->fetch()) {
                throw new InvalidArgumentException(
                    'El agente seleccionado no existe o está inactivo.'
                );
            }
        }

        $oldAgentId = $ticket['assigned_agent_id'] === null
            ? null
            : (int) $ticket['assigned_agent_id'];

        if ($oldAgentId === $newAgentId) {
            throw new InvalidArgumentException(
                'El ticket ya tiene esa asignación.'
            );
        }

        $updateStmt = $pdo->prepare(
            'UPDATE tickets
             SET assigned_agent_id = :agent_id
             WHERE id = :ticket_id'
        );
        $updateStmt->execute([
            'agent_id' => $newAgentId,
            'ticket_id' => $ticketId,
        ]);

        recordTicketChange(
            $pdo,
            $ticketId,
            $actorId,
            'agent_assigned',
            ['assigned_agent_id' => $oldAgentId],
            ['assigned_agent_id' => $newAgentId],
            'ticket.agent_assigned',
            [
            'ticket_number' => $ticket['ticket_number'],
            'old_agent_id' => $oldAgentId,
            'new_agent_id' => $newAgentId,
            ],
            $ipAddress
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}


/**
 * Actualiza el estado de un ticket y registra el historial y la auditoría.
 */
function updateTicketStatus(
    int $ticketId,
    int $actorId,
    string $newStatus,
    ?string $ipAddress = null
): void {
    $allowedStatuses = [
        'open',
        'in_progress',
        'pending',
        'resolved',
        'closed',
    ];

    // Solo agentes, supervisores y administradores.
    $allowedRoleIds = [2, 3, 4];

    if ($ticketId < 1 || $actorId < 1) {
        throw new InvalidArgumentException('El ticket o usuario no es válido.');
    }

    if (!in_array($newStatus, $allowedStatuses, true)) {
        throw new InvalidArgumentException('El estado seleccionado no es válido.');
    }

    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();

    try {
        // Validar en la base de datos el usuario y su rol actual.
        $userStmt = $pdo->prepare(
            'SELECT role_id
             FROM users
             WHERE id = :id AND is_active = 1
             FOR UPDATE'
        );
        $userStmt->execute(['id' => $actorId]);
        $user = $userStmt->fetch();

        if (!$user || !in_array((int) $user['role_id'], $allowedRoleIds, true)) {
            throw new RuntimeException(
                'No tienes permisos para cambiar el estado de los tickets.'
            );
        }

        // Bloquear el ticket para evitar cambios simultáneos.
        $ticketStmt = $pdo->prepare(
            'SELECT id, ticket_number, status, assigned_agent_id
             FROM tickets
             WHERE id = :id
             FOR UPDATE'
        );
        $ticketStmt->execute(['id' => $ticketId]);
        $ticket = $ticketStmt->fetch();

        if (!$ticket) {
            throw new RuntimeException('El ticket solicitado no existe.');
        }

        if (
            (int) $user['role_id'] === 2 &&
            (int) $ticket['assigned_agent_id'] !== $actorId
        ) {
            throw new RuntimeException(
                'Solo puedes cambiar el estado de los tickets que tienes asignados.'
            );
        }

        $oldStatus = $ticket['status'];

        if ($oldStatus === $newStatus) {
            throw new InvalidArgumentException(
                'El ticket ya tiene ese estado.'
            );
        }

        // Actualizar el estado y administrar la fecha de resolución.
        $updateStmt = $pdo->prepare(
            "UPDATE tickets
             SET status = :new_status,
                 resolved_at = CASE
                     WHEN :resolved_status = 'resolved'
                         THEN COALESCE(resolved_at, CURRENT_TIMESTAMP)
                     WHEN :active_status IN ('open', 'in_progress', 'pending')
                         THEN NULL
                     ELSE resolved_at
                 END
             WHERE id = :ticket_id"
        );

        $updateStmt->execute([
            'new_status' => $newStatus,
            'resolved_status' => $newStatus,
            'active_status' => $newStatus,
            'ticket_id' => $ticketId,
        ]);

        recordTicketChange(
            $pdo,
            $ticketId,
            $actorId,
            'status_changed',
            ['status' => $oldStatus],
            ['status' => $newStatus],
            'ticket.status_changed',
            [
                'ticket_number' => $ticket['ticket_number'],
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ],
            $ipAddress
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}
