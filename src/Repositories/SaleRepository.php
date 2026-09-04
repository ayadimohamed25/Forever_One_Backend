<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class SaleRepository {
    public function findAllByTenant(string $tenantId): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT s.*, c.name as customer_name
             FROM sales s JOIN customers c ON c.id = s.customer_id
             WHERE s.tenant_id = ? ORDER BY s.created_at DESC'
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            $saleId = Uuid::generate();
            $total = 0;
            $lines = [];

            foreach ($data['lines'] as $line) {
                $lineTotal = $line['quantity'] * $line['unit_price'];
                $total += $lineTotal;
                $lines[] = $line + ['line_total' => $lineTotal];
            }

            $stmt = $pdo->prepare(
                'INSERT INTO sales (id, tenant_id, customer_id, warehouse_id, total, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $saleId, $tenantId, $data['customer_id'], $data['warehouse_id'],
                $total, $data['status'] ?? 'confirmed',
            ]);

            $lineStmt = $pdo->prepare(
                'INSERT INTO sale_lines (id, sale_id, product_id, quantity, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $movementStmt = $pdo->prepare(
                'INSERT INTO stock_movements (id, tenant_id, product_id, warehouse_id, type, quantity, note)
                 VALUES (?, ?, ?, ?, \'out\', ?, ?)'
            );

            foreach ($lines as $line) {
                $lineStmt->execute([
                    Uuid::generate(), $saleId, $line['product_id'],
                    $line['quantity'], $line['unit_price'], $line['line_total'],
                ]);

                $movementStmt->execute([
                    Uuid::generate(), $tenantId, $line['product_id'], $data['warehouse_id'],
                    $line['quantity'], "Sale $saleId",
                ]);
            }

            $pdo->commit();
            return ['id' => $saleId, 'total' => $total, 'lines' => $lines];
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}