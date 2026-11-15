<?php
namespace App\Repositories;
use App\Config\Database;

class DashboardRepository {
    /** Trend windows the dashboard offers; anything else falls back to 30. */
    public static function trendDays($raw): int {
        $days = (int) $raw;
        return in_array($days, [7, 30, 90, 365], true) ? $days : 30;
    }

    public function getSummary(string $tenantId, int $trendDays = 30): array {
        $pdo = Database::connect();
        $trendDays = self::trendDays($trendDays);

        return [
            'kpis' => $this->kpis($pdo, $tenantId),
            'trend_days' => $trendDays,
            'sales_trend' => $this->salesTrend($pdo, $tenantId, $trendDays, 0),
            // The window of the same length just before, for the comparison line.
            'sales_trend_previous' => $this->salesTrend($pdo, $tenantId, $trendDays, 1),
            'top_products' => $this->topProducts($pdo, $tenantId),
            'alerts' => $this->alerts($pdo, $tenantId),
            'recent_activity' => $this->recentActivity($pdo, $tenantId),
        ];
    }

    // ---------- KPIs with period comparison ----------

    private function kpis(\PDO $pdo, string $tenantId): array {
        // Current month
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total), 0) FROM sales
             WHERE tenant_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        );
        $stmt->execute([$tenantId]);
        $revenueThisMonth = (float) $stmt->fetchColumn();

        // Previous month, same day range, so the comparison is fair mid-month
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total), 0) FROM sales
             WHERE tenant_id = ?
               AND created_at >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y-%m-01')
               AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)"
        );
        $stmt->execute([$tenantId]);
        $revenuePrevMonth = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COALESCE(SUM(total), 0) FROM sales WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $revenueTotal = (float) $stmt->fetchColumn();

        // Receivables
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(s.total), 0) - COALESCE((
                 SELECT SUM(p.amount) FROM payments p
                 JOIN sales s2 ON s2.id = p.sale_id
                 WHERE s2.tenant_id = ?
             ), 0)
             FROM sales s WHERE s.tenant_id = ?"
        );
        $stmt->execute([$tenantId, $tenantId]);
        $receivables = max(0, (float) $stmt->fetchColumn());

        // Payables
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(pu.total), 0) - COALESCE((
                 SELECT SUM(p.amount) FROM payments p
                 JOIN purchases pu2 ON pu2.id = p.purchase_id
                 WHERE pu2.tenant_id = ?
             ), 0)
             FROM purchases pu WHERE pu.tenant_id = ?"
        );
        $stmt->execute([$tenantId, $tenantId]);
        $payables = max(0, (float) $stmt->fetchColumn());

        // Stock value at cost — what the inventory is actually worth
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(stock * cost), 0) FROM (
                 SELECT pr.cost,
                        COALESCE(SUM(CASE WHEN sm.type='in' THEN sm.quantity
                                          WHEN sm.type='out' THEN -sm.quantity
                                          ELSE 0 END), 0) AS stock
                 FROM products pr
                 LEFT JOIN stock_movements sm ON sm.product_id = pr.id
                 WHERE pr.tenant_id = ?
                 GROUP BY pr.id, pr.cost
             ) AS inventory"
        );
        $stmt->execute([$tenantId]);
        $stockValue = (float) $stmt->fetchColumn();

        // Counts
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM sales WHERE tenant_id = ?
               AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        );
        $stmt->execute([$tenantId]);
        $salesCountThisMonth = (int) $stmt->fetchColumn();

        $growth = $revenuePrevMonth > 0
            ? (($revenueThisMonth - $revenuePrevMonth) / $revenuePrevMonth) * 100
            : null;

        return [
            'revenue_total' => round($revenueTotal, 2),
            'revenue_this_month' => round($revenueThisMonth, 2),
            'revenue_prev_month' => round($revenuePrevMonth, 2),
            'revenue_growth_percent' => $growth !== null ? round($growth, 1) : null,
            'receivables' => round($receivables, 2),
            'payables' => round($payables, 2),
            'stock_value' => round($stockValue, 2),
            'sales_count_this_month' => $salesCountThisMonth,
            'low_stock_count' => $this->lowStockCount($pdo, $tenantId),
        ];
    }

    private function lowStockCount(\PDO $pdo, string $tenantId): int {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM (
                SELECT pr.id, pr.min_threshold,
                       COALESCE(SUM(CASE WHEN sm.type='in' THEN sm.quantity
                                         WHEN sm.type='out' THEN -sm.quantity
                                         ELSE 0 END), 0) AS current_stock
                FROM products pr
                LEFT JOIN stock_movements sm ON sm.product_id = pr.id
                WHERE pr.tenant_id = ?
                GROUP BY pr.id, pr.min_threshold
                HAVING current_stock <= pr.min_threshold
            ) AS low_stock"
        );
        $stmt->execute([$tenantId]);
        return (int) $stmt->fetchColumn();
    }

    // ---------- Revenue trend ----------
    // $days = 7, 30 or 90 gives daily points; 365 gives 12 monthly points.
    // $back = 0 is the current window ending today, 1 the window before it.

    private function salesTrend(\PDO $pdo, string $tenantId, int $days, int $back): array {
        if ($days === 365) {
            $firstMonth = strtotime(date('Y-m-01') . ' -' . (11 + 12 * $back) . ' months');
            $start = date('Y-m-01', $firstMonth);
            $end = date('Y-m-01', strtotime($start . ' +12 months'));
            $stmt = $pdo->prepare(
                "SELECT DATE_FORMAT(created_at, '%Y-%m-01') AS day, COALESCE(SUM(total), 0) AS revenue
                 FROM sales
                 WHERE tenant_id = ? AND created_at >= ? AND created_at < ?
                 GROUP BY DATE_FORMAT(created_at, '%Y-%m-01')"
            );
            $stmt->execute([$tenantId, $start, $end]);
            $byDay = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $byDay[$r['day']] = (float) $r['revenue'];
            }
            $trend = [];
            for ($i = 0; $i < 12; $i++) {
                $day = date('Y-m-01', strtotime($start . " +$i months"));
                $trend[] = ['day' => $day, 'revenue' => round($byDay[$day] ?? 0, 2)];
            }
            return $trend;
        }

        $lastOffset = $days * $back;              // days before today of the window end
        $firstOffset = $lastOffset + $days - 1;   // days before today of the window start
        $start = date('Y-m-d', strtotime("-$firstOffset days"));
        // Exclusive end: the day after the window (tomorrow for the current one).
        // sprintf('%+d') keeps the sign explicit; '--1 days' would mean yesterday.
        $end = date('Y-m-d', strtotime(date('Y-m-d') . ' ' . sprintf('%+d', 1 - $lastOffset) . ' days'));
        $stmt = $pdo->prepare(
            "SELECT DATE(created_at) AS day, COALESCE(SUM(total), 0) AS revenue
             FROM sales
             WHERE tenant_id = ? AND created_at >= ? AND created_at < ?
             GROUP BY DATE(created_at)"
        );
        $stmt->execute([$tenantId, $start, $end]);

        // Fill missing days with zero so the chart has no gaps.
        $byDay = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $byDay[$r['day']] = (float) $r['revenue'];
        }
        $trend = [];
        for ($i = $firstOffset; $i >= $lastOffset; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $trend[] = ['day' => $day, 'revenue' => round($byDay[$day] ?? 0, 2)];
        }
        return $trend;
    }

    // ---------- Top products by revenue ----------

    private function topProducts(\PDO $pdo, string $tenantId): array {
        $stmt = $pdo->prepare(
            "SELECT p.name,
                    SUM(sl.quantity) AS quantity_sold,
                    SUM(sl.line_total) AS revenue
             FROM sale_lines sl
             JOIN sales s ON s.id = sl.sale_id
             JOIN products p ON p.id = sl.product_id
             WHERE s.tenant_id = ?
             GROUP BY p.id, p.name
             ORDER BY revenue DESC
             LIMIT 5"
        );
        $stmt->execute([$tenantId]);

        return array_map(fn($r) => [
            'name' => $r['name'],
            'quantity_sold' => (int) $r['quantity_sold'],
            'revenue' => round((float) $r['revenue'], 2),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    // ---------- Actionable alerts ----------

    private function alerts(\PDO $pdo, string $tenantId): array {
        $alerts = [];

        // Products at or below threshold
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
             ORDER BY current_stock ASC
             LIMIT 5"
        );
        $stmt->execute([$tenantId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $alerts[] = [
                'type' => 'low_stock',
                'severity' => (int) $p['current_stock'] <= 0 ? 'critical' : 'warning',
                'entity_id' => $p['id'],
                'title' => $p['name'],
                'value' => (int) $p['current_stock'],
                'route' => '/products',
            ];
        }

        // Customers over their credit limit
        $stmt = $pdo->prepare(
            "SELECT c.id, c.name, c.credit_limit,
                    COALESCE((SELECT SUM(s.total) FROM sales s WHERE s.customer_id = c.id), 0)
                  - COALESCE((SELECT SUM(pay.amount) FROM payments pay
                              JOIN sales s2 ON s2.id = pay.sale_id
                              WHERE s2.customer_id = c.id), 0) AS balance
             FROM customers c
             WHERE c.tenant_id = ? AND c.credit_limit > 0
             HAVING balance > c.credit_limit
             LIMIT 5"
        );
        $stmt->execute([$tenantId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $alerts[] = [
                'type' => 'credit_exceeded',
                'severity' => 'critical',
                'entity_id' => $c['id'],
                'title' => $c['name'],
                'value' => round((float) $c['balance'], 2),
                'route' => '/customers',
            ];
        }

        // Sales past their due date and still unpaid
        $stmt = $pdo->prepare(
            "SELECT s.id, c.name AS customer_name, s.total, s.due_date,
                    DATEDIFF(CURDATE(), s.due_date) AS days_overdue,
                    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.sale_id = s.id), 0) AS paid
             FROM sales s
             JOIN customers c ON c.id = s.customer_id
             WHERE s.tenant_id = ?
               AND s.due_date IS NOT NULL
               AND s.due_date < CURDATE()
             HAVING s.total - paid > 0.009
             ORDER BY days_overdue DESC
             LIMIT 5"
        );
        $stmt->execute([$tenantId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $s) {
            $alerts[] = [
                'type' => 'overdue_payment',
                'severity' => 'critical',
                'entity_id' => $s['id'],
                'title' => $s['customer_name'],
                'value' => round((float) $s['total'] - (float) $s['paid'], 2),
                'days' => (int) $s['days_overdue'],
                'route' => '/sales',
            ];
        }

        return $alerts;
    }

    // ---------- Recent activity ----------

    private function recentActivity(\PDO $pdo, string $tenantId): array {
        $stmt = $pdo->prepare(
            "SELECT 'sale' AS kind, s.id, c.name AS label, s.total AS amount, s.created_at
             FROM sales s JOIN customers c ON c.id = s.customer_id
             WHERE s.tenant_id = ?
             UNION ALL
             SELECT 'purchase' AS kind, p.id, su.name AS label, p.total AS amount, p.created_at
             FROM purchases p JOIN suppliers su ON su.id = p.supplier_id
             WHERE p.tenant_id = ?
             ORDER BY created_at DESC
             LIMIT 8"
        );
        $stmt->execute([$tenantId, $tenantId]);

        return array_map(fn($r) => [
            'kind' => $r['kind'],
            'id' => $r['id'],
            'label' => $r['label'],
            'amount' => round((float) $r['amount'], 2),
            'created_at' => $r['created_at'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
}