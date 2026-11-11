<?php
namespace App\Repositories;

use App\Config\Database;

/// Every active alert for a tenant, grouped by type, for the in-app
/// Notifications screen and the bell badge. Same rules as the dashboard
/// alerts, without the dashboard's "top 5" limit, plus supplier payments due
/// and pending deliveries. Each item names the entity it opens.
class NotificationRepository {
    /// $allowed: the notification types the caller may see (by permission).
    public function forTenant(string $tenantId, array $allowed): array {
        $pdo = Database::connect();
        $items = [];

        if (in_array('out_of_stock', $allowed, true) || in_array('low_stock', $allowed, true)) {
            foreach ($this->stock($pdo, $tenantId) as $p) {
                $out = (float) $p['current_stock'] <= 0;
                $type = $out ? 'out_of_stock' : 'low_stock';
                if (!in_array($type, $allowed, true)) continue;
                $items[] = [
                    'type' => $type,
                    'severity' => $out ? 'critical' : 'warning',
                    'entity' => 'product',
                    'entity_id' => $p['id'],
                    'title' => $p['name'],
                    'value' => (float) $p['current_stock'],
                    'threshold' => (float) $p['min_threshold'],
                ];
            }
        }

        if (in_array('overdue_payment', $allowed, true)) {
            $stmt = $pdo->prepare(
                "SELECT s.id, c.name AS customer_name, s.total, s.due_date,
                        DATEDIFF(CURDATE(), s.due_date) AS days_overdue,
                        COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.sale_id = s.id), 0) AS paid
                 FROM sales s
                 JOIN customers c ON c.id = s.customer_id
                 WHERE s.tenant_id = ? AND s.status <> 'cancelled'
                   AND s.due_date IS NOT NULL AND s.due_date < CURDATE()
                 HAVING s.total - paid > 0.009
                 ORDER BY days_overdue DESC"
            );
            $stmt->execute([$tenantId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $s) {
                $items[] = [
                    'type' => 'overdue_payment',
                    'severity' => 'critical',
                    'entity' => 'sale',
                    'entity_id' => $s['id'],
                    'title' => $s['customer_name'],
                    'value' => round((float) $s['total'] - (float) $s['paid'], 2),
                    'days' => (int) $s['days_overdue'],
                ];
            }
        }

        if (in_array('credit_exceeded', $allowed, true)) {
            $stmt = $pdo->prepare(
                "SELECT c.id, c.name, c.credit_limit,
                        COALESCE((SELECT SUM(s.total) FROM sales s WHERE s.customer_id = c.id AND s.status <> 'cancelled'), 0)
                      - COALESCE((SELECT SUM(pay.amount) FROM payments pay
                                  JOIN sales s2 ON s2.id = pay.sale_id
                                  WHERE s2.customer_id = c.id), 0) AS balance
                 FROM customers c
                 WHERE c.tenant_id = ? AND c.credit_limit > 0
                 HAVING balance > c.credit_limit
                 ORDER BY balance - c.credit_limit DESC"
            );
            $stmt->execute([$tenantId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $items[] = [
                    'type' => 'credit_exceeded',
                    'severity' => 'critical',
                    'entity' => 'customer',
                    'entity_id' => $c['id'],
                    'title' => $c['name'],
                    'value' => round((float) $c['balance'], 2),
                    'threshold' => (float) $c['credit_limit'],
                ];
            }
        }

        if (in_array('supplier_payment_due', $allowed, true)) {
            // Unpaid purchases whose payment term (created + supplier terms)
            // falls within the next 7 days or has passed.
            $stmt = $pdo->prepare(
                "SELECT pu.id, sp.name AS supplier_name, pu.total,
                        DATE_ADD(DATE(pu.created_at), INTERVAL sp.payment_terms_days DAY) AS due_date,
                        DATEDIFF(DATE_ADD(DATE(pu.created_at), INTERVAL sp.payment_terms_days DAY), CURDATE()) AS days_left,
                        COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.purchase_id = pu.id), 0) AS paid
                 FROM purchases pu
                 JOIN suppliers sp ON sp.id = pu.supplier_id
                 WHERE pu.tenant_id = ? AND pu.status <> 'cancelled'
                 HAVING pu.total - paid > 0.009 AND days_left <= 7
                 ORDER BY days_left ASC"
            );
            $stmt->execute([$tenantId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $p) {
                $items[] = [
                    'type' => 'supplier_payment_due',
                    'severity' => (int) $p['days_left'] < 0 ? 'critical' : 'warning',
                    'entity' => 'purchase',
                    'entity_id' => $p['id'],
                    'title' => $p['supplier_name'],
                    'value' => round((float) $p['total'] - (float) $p['paid'], 2),
                    'days' => (int) $p['days_left'],
                ];
            }
        }

        if (in_array('pending_delivery', $allowed, true)) {
            $stmt = $pdo->prepare(
                "SELECT pu.id, sp.name AS supplier_name, pu.total, pu.expected_date,
                        DATEDIFF(pu.expected_date, CURDATE()) AS days_left
                 FROM purchases pu
                 JOIN suppliers sp ON sp.id = pu.supplier_id
                 WHERE pu.tenant_id = ? AND pu.status = 'draft'
                 ORDER BY pu.expected_date IS NULL, pu.expected_date ASC"
            );
            $stmt->execute([$tenantId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $p) {
                $late = $p['expected_date'] !== null && (int) $p['days_left'] < 0;
                $items[] = [
                    'type' => 'pending_delivery',
                    'severity' => $late ? 'critical' : 'info',
                    'entity' => 'purchase',
                    'entity_id' => $p['id'],
                    'title' => $p['supplier_name'],
                    'value' => round((float) $p['total'], 2),
                    'days' => $p['expected_date'] === null ? null : (int) $p['days_left'],
                ];
            }
        }

        return ['count' => count($items), 'items' => $items];
    }

    private function stock(\PDO $pdo, string $tenantId): array {
        $stmt = $pdo->prepare(
            "SELECT pr.id, pr.name, pr.min_threshold,
                    COALESCE(SUM(CASE WHEN sm.type='in' THEN sm.quantity
                                      WHEN sm.type='out' THEN -sm.quantity
                                      ELSE 0 END), 0) AS current_stock
             FROM products pr
             LEFT JOIN stock_movements sm ON sm.product_id = pr.id
             WHERE pr.tenant_id = ?
             GROUP BY pr.id, pr.name, pr.min_threshold
             HAVING current_stock <= pr.min_threshold
             ORDER BY current_stock ASC, pr.name ASC"
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
