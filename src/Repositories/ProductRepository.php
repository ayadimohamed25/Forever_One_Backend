<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class ProductRepository {
    public function findAllByTenant(string $tenantId): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM products WHERE tenant_id = ? ORDER BY created_at DESC');
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $id = Uuid::generate();
        $stmt = $pdo->prepare(
            'INSERT INTO products (id, tenant_id, category_id, name, barcode, price, cost, min_threshold, unit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $tenantId,
            $data['category_id'] ?? null,
            $data['name'],
            $data['barcode'] ?? null,
            $data['price'] ?? 0,
            $data['cost'] ?? 0,
            $data['min_threshold'] ?? 0,
            $data['unit'] ?? 'unit',
        ]);
        return ['id' => $id] + $data;
    }
}