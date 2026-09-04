<?php
namespace App\Repositories;
use App\Config\Database;

class WarehouseRepository {
    public function findAllByTenant(string $tenantId): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM warehouses WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}