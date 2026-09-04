<?php
namespace App\Repositories;
use App\Config\Database;

class DashboardRepository {
    public function getSummary(string $tenantId): array {
        $pdo = Database::connect();

        $stmt = $pdo->prepare('SELECT COALESCE(SUM(total), 0) FROM sales WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $revenue = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(s.total), 0) - COALESCE((
                SELECT SUM(p.amount) FROM payments p
                JOIN sales s2 ON s2.id = p.sale_id
                WHERE s2.tenant_id = ?
             ), 0)
             FROM sales s WHERE s.tenant_id = ?'
        );
        $stmt->execute([$tenantId, $tenantId]);
        $receivables = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(pu.total), 0) - COALESCE((
                SELECT SUM(p.amount) FROM payments p
                JOIN purchases pu2 ON pu2.id = p.purchase_id
                WHERE pu2.tenant_id = ?
             ), 0)
             FROM purchases pu WHERE pu.tenant_id = ?'
        );
        $stmt->execute([$tenantId, $tenantId]);
        $payables = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM (
                SELECT pr.id, pr.min_threshold,
                       COALESCE(SUM(CASE WHEN sm.type='in' THEN sm.quantity
                                         WHEN sm.type='out' THEN -sm.quantity
                                         ELSE 0 END), 0) as current_stock
                FROM products pr
                LEFT JOIN stock_movements sm ON sm.product_id = pr.id
                WHERE pr.tenant_id = ?
                GROUP BY pr.id, pr.min_threshold
                HAVING current_stock <= pr.min_threshold
            ) as low_stock"
        );
        $stmt->execute([$tenantId]);
        $lowStockCount = (int) $stmt->fetchColumn();

        return [
            'revenue' => $revenue,
            'receivables' => max(0, $receivables),
            'payables' => max(0, $payables),
            'low_stock_count' => $lowStockCount,
        ];
    }
}