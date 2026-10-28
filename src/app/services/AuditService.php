<?php
declare(strict_types=1);

function recordAuditEntry(
    PDO $pdo,
    int $actorId,
    string $action,
    string $entityType,
    ?int $entityId,
    array $details,
    ?string $ipAddress
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs
            (actor_id, action, entity_type, entity_id, details, ip_address)
         VALUES
            (:actor_id, :action, :entity_type, :entity_id, :details, :ip_address)'
    );
    $stmt->execute([
        'actor_id' => $actorId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'details' => json_encode(
            $details,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ),
        'ip_address' => $ipAddress,
    ]);
}
