<?php
declare(strict_types=1);

final class Audit {
    public static function log(?int $actorUserId, string $action, string $entityType, ?int $entityId, array $meta = []): void {
        $stmt = db()->prepare('
            INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, ip, user_agent, created_at, meta_json)
            VALUES (:actor, :action, :etype, :eid, :ip, :ua, :created, :meta)
        ');
        $stmt->execute([
            ':actor' => $actorUserId,
            ':action' => $action,
            ':etype' => $entityType,
            ':eid' => $entityId,
            ':ip' => client_ip(),
            ':ua' => user_agent(),
            ':created' => db_now_utc(),
            ':meta' => json_encode($meta, JSON_UNESCAPED_SLASHES),
        ]);
    }
}
