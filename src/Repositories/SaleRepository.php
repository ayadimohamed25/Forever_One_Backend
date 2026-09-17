<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class SaleRepository {
    public function findAllByTenant(string $tenantId): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT s.*, c.name as customer_name,
                    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.sale_id = s.id), 0) AS paid
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
            $subtotalHt = 0;
            $totalVat = 0;
            $lines = [];

            foreach ($data['lines'] as $line) {
                $lineHt = $line['quantity'] * $line['unit_price'];
                $vatRate = isset($line['vat_rate']) ? (float) $line['vat_rate'] : 0;
                $vatAmount = $lineHt * $vatRate / 100;

                $subtotalHt += $lineHt;
                $totalVat += $vatAmount;

                $lines[] = $line + [
                    'line_total' => round($lineHt, 3),
                    'vat_rate' => $vatRate,
                    'vat_amount' => round($vatAmount, 3),
                ];
            }

            $total = $subtotalHt + $totalVat;

            // Due date comes from the customer's payment terms when not given.
            $dueDate = $data['due_date'] ?? null;
            if ($dueDate === null) {
                $stmt = $pdo->prepare('SELECT payment_terms_days FROM customers WHERE id = ?');
                $stmt->execute([$data['customer_id']]);
                $terms = (int) $stmt->fetchColumn();
                if ($terms > 0) {
                    $dueDate = date('Y-m-d', strtotime("+$terms days"));
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO sales
                 (id, tenant_id, customer_id, warehouse_id, reference, due_date,
                  subtotal_ht, total_vat, total, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $saleId, $tenantId, $data['customer_id'], $data['warehouse_id'],
                $data['reference'] ?? null,
                $dueDate,
                round($subtotalHt, 3),
                round($totalVat, 3),
                round($total, 3),
                $data['status'] ?? 'confirmed',
                $data['notes'] ?? null,
            ]);

            $lineStmt = $pdo->prepare(
                'INSERT INTO sale_lines
                 (id, sale_id, product_id, quantity, unit_price, vat_rate, line_total, vat_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $movementStmt = $pdo->prepare(
                'INSERT INTO stock_movements (id, tenant_id, product_id, warehouse_id, type, quantity, note)
                 VALUES (?, ?, ?, ?, \'out\', ?, ?)'
            );

            foreach ($lines as $line) {
                $lineStmt->execute([
                    Uuid::generate(), $saleId, $line['product_id'],
                    $line['quantity'], $line['unit_price'], $line['vat_rate'],
                    $line['line_total'], $line['vat_amount'],
                ]);

                $movementStmt->execute([
                    Uuid::generate(), $tenantId, $line['product_id'], $data['warehouse_id'],
                    $line['quantity'], "Sale $saleId",
                ]);
            }

            $pdo->commit();

            return [
                'id' => $saleId,
                'subtotal_ht' => round($subtotalHt, 3),
                'total_vat' => round($totalVat, 3),
                'total' => round($total, 3),
                'due_date' => $dueDate,
                'lines' => $lines,
            ];
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}