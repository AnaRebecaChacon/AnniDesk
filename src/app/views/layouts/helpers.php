<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function userRoleLabels(): array
{
    return [
        1 => 'Solicitante',
        2 => 'Agente de soporte',
        3 => 'Supervisor',
        4 => 'Administrador',
        5 => 'Auditor de solo lectura',
    ];
}

function ticketStatusLabels(): array
{
    return [
        'open' => 'Abierto',
        'in_progress' => 'En progreso',
        'pending' => 'Pendiente',
        'resolved' => 'Resuelto',
        'closed' => 'Cerrado',
    ];
}

function ticketPriorityLabels(): array
{
    return [
        'low' => 'Baja',
        'medium' => 'Media',
        'high' => 'Alta',
        'urgent' => 'Urgente',
    ];
}
