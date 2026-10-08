<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class AuditService
{
    public function __construct(private readonly DB $db)
    {
    }

    public function record(
        int $userId,
        string $action,
        string $entity,
        ?int $entityId,
        ?array $oldState,
        ?array $newState,
        ?string $ip
    ): void {
        $this->db->execute(
            'INSERT INTO audit_log (user_id, action, entity, entity_id, old_json, new_json, ip)
             VALUES (:user_id, :action, :entity, :entity_id, :old_json, :new_json, :ip)',
            [
                'user_id' => $userId,
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $entityId,
                'old_json' => $oldState === null ? null : json_encode($oldState, JSON_THROW_ON_ERROR),
                'new_json' => $newState === null ? null : json_encode($newState, JSON_THROW_ON_ERROR),
                'ip' => $ip,
            ]
        );
    }
}
