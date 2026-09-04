<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class PaymentRepository {
    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $id = Uuid::generate();
        $stmt = $pdo->prepare(
            'INSERT INTO payments (id, tenant_id, sale_id, purchase_id, amount, method)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $tenantId,
            $data['sale_id'] ?? null,
            $data['purchase_id'] ?? null,
            $data['amount'],
            $data['method'] ?? 'cash',
        ]);
        return ['id' => $id] + $data;
    }

    public function getSaleBalance(string $tenantId, string $saleId): array {
        $pdo = Database::connect();

        $stmt = $pdo->prepare('SELECT total FROM sales WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$saleId, $tenantId]);
        $total = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = ? AND tenant_id = ?');
        $stmt->execute([$saleId, $tenantId]);
        $paid = (float) $stmt->fetchColumn();

        return ['total' => $total, 'paid' => $paid, 'balance' => $total - $paid];
    }

    public function getPurchaseBalance(string $tenantId, string $purchaseId): array {
        $pdo = Database::connect();

        $stmt = $pdo->prepare('SELECT total FROM purchases WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$purchaseId, $tenantId]);
        $total = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE purchase_id = ? AND tenant_id = ?');
        $stmt->execute([$purchaseId, $tenantId]);
        $paid = (float) $stmt->fetchColumn();

        return ['total' => $total, 'paid' => $paid, 'balance' => $total - $paid];
    }
}