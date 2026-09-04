<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class StockMovementRepository {
    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $id = Uuid::generate();
        $stmt = $pdo->prepare(
            'INSERT INTO stock_movements (id, tenant_id, product_id, warehouse_id, type, quantity, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $tenantId,
            $data['product_id'],
            $data['warehouse_id'],
            $data['type'],
            $data['quantity'],
            $data['note'] ?? null,
        ]);
        return ['id' => $id] + $data;
    }

    public function currentStock(string $tenantId, string $productId, string $warehouseId): int {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN type = 'in' THEN quantity
                                       WHEN type = 'out' THEN -quantity
                                       ELSE 0 END), 0) as stock
             FROM stock_movements
             WHERE tenant_id = ? AND product_id = ? AND warehouse_id = ?"
        );
        $stmt->execute([$tenantId, $productId, $warehouseId]);
        return (int) $stmt->fetchColumn();
    }
}