<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class PurchaseRepository {
    public function findAllByTenant(string $tenantId): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT p.*, s.name as supplier_name
             FROM purchases p JOIN suppliers s ON s.id = p.supplier_id
             WHERE p.tenant_id = ? ORDER BY p.created_at DESC'
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            $purchaseId = Uuid::generate();
            $total = 0;
            $lines = [];

            foreach ($data['lines'] as $line) {
                $lineTotal = $line['quantity'] * $line['unit_cost'];
                $total += $lineTotal;
                $lines[] = $line + ['line_total' => $lineTotal];
            }

            $stmt = $pdo->prepare(
                'INSERT INTO purchases (id, tenant_id, supplier_id, warehouse_id, total, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $purchaseId, $tenantId, $data['supplier_id'], $data['warehouse_id'],
                $total, $data['status'] ?? 'received',
            ]);

            $lineStmt = $pdo->prepare(
                'INSERT INTO purchase_lines (id, purchase_id, product_id, quantity, unit_cost, line_total)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $movementStmt = $pdo->prepare(
                'INSERT INTO stock_movements (id, tenant_id, product_id, warehouse_id, type, quantity, note)
                 VALUES (?, ?, ?, ?, \'in\', ?, ?)'
            );

            foreach ($lines as $line) {
                $lineStmt->execute([
                    Uuid::generate(), $purchaseId, $line['product_id'],
                    $line['quantity'], $line['unit_cost'], $line['line_total'],
                ]);

                $movementStmt->execute([
                    Uuid::generate(), $tenantId, $line['product_id'], $data['warehouse_id'],
                    $line['quantity'], "Purchase $purchaseId",
                ]);
            }

            $pdo->commit();
            return ['id' => $purchaseId, 'total' => $total, 'lines' => $lines];
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}