<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class SaleRepository {
    public function findAllByTenant(string $tenantId, ?string $search = null): array {
        $pdo = Database::connect();

        $sql = 'SELECT s.*, c.name as customer_name,
                       COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.sale_id = s.id), 0) AS paid
                FROM sales s JOIN customers c ON c.id = s.customer_id
                WHERE s.tenant_id = ?';
        $params = [$tenantId];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (c.name LIKE ? OR s.reference LIKE ?)';
            $like = '%' . trim($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY s.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(string $tenantId, string $id): ?array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT s.*, c.name as customer_name,
                    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.sale_id = s.id), 0) AS paid
             FROM sales s JOIN customers c ON c.id = s.customer_id
             WHERE s.tenant_id = ? AND s.id = ?'
        );
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function lines(string $saleId): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT sl.*, p.name AS product_name
             FROM sale_lines sl
             JOIN products p ON p.id = sl.product_id
             WHERE sl.sale_id = ?'
        );
        $stmt->execute([$saleId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /// A sale with payments recorded against it is never deleted or edited —
    /// that would orphan the payment records.
    public function hasPayments(string $saleId): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE sale_id = ?');
        $stmt->execute([$saleId]);
        return (int) $stmt->fetchColumn() > 0;
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

    /// Replaces the sale's lines and re-syncs the stock movements.
    public function update(string $tenantId, string $id, array $data): bool {
        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
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

            $stmt = $pdo->prepare(
                'UPDATE sales
                 SET customer_id = ?, warehouse_id = ?, reference = ?,
                     subtotal_ht = ?, total_vat = ?, total = ?, notes = ?
                 WHERE id = ? AND tenant_id = ?'
            );
            $stmt->execute([
                $data['customer_id'], $data['warehouse_id'],
                $data['reference'] ?? null,
                round($subtotalHt, 3), round($totalVat, 3), round($total, 3),
                $data['notes'] ?? null,
                $id, $tenantId,
            ]);

            // Rebuild lines and movements from scratch — simpler and safer
            // than diffing, and the transaction keeps it atomic.
            $stmt = $pdo->prepare('DELETE FROM sale_lines WHERE sale_id = ?');
            $stmt->execute([$id]);

            $stmt = $pdo->prepare(
                'DELETE FROM stock_movements WHERE tenant_id = ? AND note = ?'
            );
            $stmt->execute([$tenantId, "Sale $id"]);

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
                    Uuid::generate(), $id, $line['product_id'],
                    $line['quantity'], $line['unit_price'], $line['vat_rate'],
                    $line['line_total'], $line['vat_amount'],
                ]);
                $movementStmt->execute([
                    Uuid::generate(), $tenantId, $line['product_id'],
                    $data['warehouse_id'], $line['quantity'], "Sale $id",
                ]);
            }

            $pdo->commit();
            return true;
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /// Deletes the sale and puts the stock back where it came from,
    /// so inventory stays truthful.
    public function delete(string $tenantId, string $id): bool {
        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT id FROM sales WHERE id = ? AND tenant_id = ?'
            );
            $stmt->execute([$id, $tenantId]);
            if (!$stmt->fetchColumn()) {
                $pdo->rollBack();
                return false;
            }

            // Remove the original "out" movements rather than adding
            // compensating ones, so the history isn't cluttered.
            $stmt = $pdo->prepare(
                'DELETE FROM stock_movements WHERE tenant_id = ? AND note = ?'
            );
            $stmt->execute([$tenantId, "Sale $id"]);

            $stmt = $pdo->prepare('DELETE FROM sale_lines WHERE sale_id = ?');
            $stmt->execute([$id]);

            $stmt = $pdo->prepare('DELETE FROM sales WHERE id = ? AND tenant_id = ?');
            $stmt->execute([$id, $tenantId]);

            $pdo->commit();
            return true;
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}