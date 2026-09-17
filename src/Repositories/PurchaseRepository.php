<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class PurchaseRepository {
    public function findAllByTenant(string $tenantId): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT p.*, s.name as supplier_name,
                    COALESCE((SELECT SUM(pay.amount) FROM payments pay WHERE pay.purchase_id = p.id), 0) AS paid
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
            $subtotalHt = 0;
            $totalVat = 0;
            $lines = [];

            foreach ($data['lines'] as $line) {
                $lineHt = $line['quantity'] * $line['unit_cost'];
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

            // Expected delivery date falls back to the supplier's lead time.
            $expectedDate = $data['expected_date'] ?? null;
            if ($expectedDate === null) {
                $stmt = $pdo->prepare('SELECT lead_time_days FROM suppliers WHERE id = ?');
                $stmt->execute([$data['supplier_id']]);
                $leadTime = (int) $stmt->fetchColumn();
                if ($leadTime > 0) {
                    $expectedDate = date('Y-m-d', strtotime("+$leadTime days"));
                }
            }

            $status = $data['status'] ?? 'received';
            // A purchase marked received today records the actual date, which is
            // what makes supplier reliability measurable.
            $receivedDate = $data['received_date']
                ?? ($status === 'received' ? date('Y-m-d') : null);

            $stmt = $pdo->prepare(
                'INSERT INTO purchases
                 (id, tenant_id, supplier_id, warehouse_id, reference,
                  expected_date, received_date, subtotal_ht, total_vat, total, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $purchaseId, $tenantId, $data['supplier_id'], $data['warehouse_id'],
                $data['reference'] ?? null,
                $expectedDate,
                $receivedDate,
                round($subtotalHt, 3),
                round($totalVat, 3),
                round($total, 3),
                $status,
                $data['notes'] ?? null,
            ]);

            $lineStmt = $pdo->prepare(
                'INSERT INTO purchase_lines
                 (id, purchase_id, product_id, quantity, unit_cost, vat_rate, line_total, vat_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $movementStmt = $pdo->prepare(
                'INSERT INTO stock_movements (id, tenant_id, product_id, warehouse_id, type, quantity, note)
                 VALUES (?, ?, ?, ?, \'in\', ?, ?)'
            );

            foreach ($lines as $line) {
                $lineStmt->execute([
                    Uuid::generate(), $purchaseId, $line['product_id'],
                    $line['quantity'], $line['unit_cost'], $line['vat_rate'],
                    $line['line_total'], $line['vat_amount'],
                ]);

                // Only move stock once the goods are actually received.
                if ($status === 'received') {
                    $movementStmt->execute([
                        Uuid::generate(), $tenantId, $line['product_id'], $data['warehouse_id'],
                        $line['quantity'], "Purchase $purchaseId",
                    ]);
                }
            }

            $pdo->commit();

            return [
                'id' => $purchaseId,
                'subtotal_ht' => round($subtotalHt, 3),
                'total_vat' => round($totalVat, 3),
                'total' => round($total, 3),
                'expected_date' => $expectedDate,
                'received_date' => $receivedDate,
                'lines' => $lines,
            ];
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /// Marks a draft purchase as received: sets the date and moves the stock.
    public function markReceived(string $tenantId, string $id, ?string $receivedDate): bool {
        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM purchases WHERE id = ? AND tenant_id = ?'
            );
            $stmt->execute([$id, $tenantId]);
            $purchase = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$purchase || $purchase['status'] === 'received') {
                $pdo->rollBack();
                return false;
            }

            $date = $receivedDate ?? date('Y-m-d');

            $stmt = $pdo->prepare(
                "UPDATE purchases SET status = 'received', received_date = ?
                 WHERE id = ? AND tenant_id = ?"
            );
            $stmt->execute([$date, $id, $tenantId]);

            $stmt = $pdo->prepare('SELECT product_id, quantity FROM purchase_lines WHERE purchase_id = ?');
            $stmt->execute([$id]);
            $lines = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $movementStmt = $pdo->prepare(
                'INSERT INTO stock_movements (id, tenant_id, product_id, warehouse_id, type, quantity, note)
                 VALUES (?, ?, ?, ?, \'in\', ?, ?)'
            );

            foreach ($lines as $line) {
                $movementStmt->execute([
                    Uuid::generate(), $tenantId, $line['product_id'],
                    $purchase['warehouse_id'], $line['quantity'], "Purchase $id",
                ]);
            }

            $pdo->commit();
            return true;
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}