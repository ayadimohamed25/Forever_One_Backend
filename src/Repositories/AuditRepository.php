<?php
namespace App\Repositories;
use App\Config\Database;

class AuditRepository {
    public function findAllByTenant(string $tenantId, int $limit = 100): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            "SELECT a.*, u.email as user_email
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.tenant_id = ?
             ORDER BY a.created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $tenantId);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}