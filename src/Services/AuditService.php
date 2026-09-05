<?php
namespace App\Services;
use App\Config\Database;
use App\Core\Uuid;

class AuditService {
    public static function log(
        string $tenantId,
        ?string $userId,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $details = null
    ): void {
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, details)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                Uuid::generate(), $tenantId, $userId, $action,
                $entityType, $entityId,
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\Exception $e) {
            // Audit logging must never break the main operation
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }
}