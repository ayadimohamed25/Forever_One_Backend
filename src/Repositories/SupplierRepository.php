<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class SupplierRepository {
    public function findAllByTenant(string $tenantId): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE tenant_id = ? ORDER BY created_at DESC');
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $id = Uuid::generate();
        $stmt = $pdo->prepare(
            'INSERT INTO suppliers (id, tenant_id, name, phone, email, lead_time_days) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $tenantId, $data['name'],
            $data['phone'] ?? null, $data['email'] ?? null, $data['lead_time_days'] ?? 0,
        ]);
        return ['id' => $id] + $data;
    }
}