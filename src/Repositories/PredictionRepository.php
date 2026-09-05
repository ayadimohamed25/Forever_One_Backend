<?php
namespace App\Repositories;
use App\Config\Database;

class PredictionRepository {
    public function stockForecast(string $tenantId): array {
        $pdo = Database::connect();

        $stmt = $pdo->prepare(
            "SELECT p.id, p.name, p.min_threshold,
                    COALESCE(SUM(CASE WHEN sm.type='in' THEN sm.quantity
                                      WHEN sm.type='out' THEN -sm.quantity ELSE 0 END), 0) as current_stock
             FROM products p
             LEFT JOIN stock_movements sm ON sm.product_id = p.id
             WHERE p.tenant_id = ?
             GROUP BY p.id, p.name, p.min_threshold"
        );
        $stmt->execute([$tenantId]);
        $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $results = [];
        foreach ($products as $p) {
            // Average daily sales over the last 30 days
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(sl.quantity), 0) as sold,
                        GREATEST(DATEDIFF(NOW(), MIN(s.created_at)), 1) as days_span
                 FROM sale_lines sl
                 JOIN sales s ON s.id = sl.sale_id
                 WHERE s.tenant_id = ? AND sl.product_id = ?
                   AND s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
            $stmt->execute([$tenantId, $p['id']]);
            $sales = $stmt->fetch(\PDO::FETCH_ASSOC);

            $dailyRate = $sales['sold'] > 0 ? $sales['sold'] / $sales['days_span'] : 0;
            $daysOfCoverage = $dailyRate > 0 ? floor($p['current_stock'] / $dailyRate) : null;

            // Suggest ordering enough for 30 days plus the safety threshold
            $suggestedOrder = $dailyRate > 0
                ? max(0, ceil(($dailyRate * 30) + $p['min_threshold'] - $p['current_stock']))
                : 0;

            $urgency = 'ok';
            if ($p['current_stock'] <= $p['min_threshold']) {
                $urgency = 'critical';
            } elseif ($daysOfCoverage !== null && $daysOfCoverage <= 7) {
                $urgency = 'warning';
            }

            $results[] = [
                'product_id' => $p['id'],
                'name' => $p['name'],
                'current_stock' => (int) $p['current_stock'],
                'min_threshold' => (int) $p['min_threshold'],
                'daily_sales_rate' => round($dailyRate, 2),
                'days_of_coverage' => $daysOfCoverage,
                'suggested_order' => (int) $suggestedOrder,
                'urgency' => $urgency,
            ];
        }

        // Most urgent first
        usort($results, function ($a, $b) {
            $order = ['critical' => 0, 'warning' => 1, 'ok' => 2];
            return $order[$a['urgency']] <=> $order[$b['urgency']];
        });

        return $results;
    }

    public function dormantProducts(string $tenantId, int $days = 30): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            "SELECT p.id, p.name,
                    MAX(s.created_at) as last_sale,
                    COALESCE(DATEDIFF(NOW(), MAX(s.created_at)), 9999) as days_since_sale
             FROM products p
             LEFT JOIN sale_lines sl ON sl.product_id = p.id
             LEFT JOIN sales s ON s.id = sl.sale_id AND s.tenant_id = ?
             WHERE p.tenant_id = ?
             GROUP BY p.id, p.name
             HAVING days_since_sale >= ?
             ORDER BY days_since_sale DESC"
        );
        
        $stmt->execute([$tenantId, $tenantId, $days]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            $neverSold = $r['last_sale'] === null;
            return [
                'id' => $r['id'],
                'name' => $r['name'],
                'last_sale' => $r['last_sale'],
                'days_since_sale' => $neverSold ? null : (int) $r['days_since_sale'],
                'never_sold' => $neverSold,
            ];
        }, $rows);
    }

    public function customerScoring(string $tenantId): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            "SELECT c.id, c.name, c.phone, c.credit_limit,
                    COALESCE(SUM(s.total), 0) as total_sales,
                    COALESCE((SELECT SUM(pay.amount) FROM payments pay
                              JOIN sales s2 ON s2.id = pay.sale_id
                              WHERE s2.customer_id = c.id), 0) as total_paid,
                    MAX(s.created_at) as last_purchase,
                    COALESCE(DATEDIFF(NOW(), MAX(s.created_at)), 9999) as days_since_purchase
             FROM customers c
             LEFT JOIN sales s ON s.customer_id = c.id
             WHERE c.tenant_id = ?
             GROUP BY c.id, c.name, c.phone, c.credit_limit"
        );
        $stmt->execute([$tenantId]);
        $customers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $results = [];
        foreach ($customers as $c) {
            $balance = (float) $c['total_sales'] - (float) $c['total_paid'];
            $daysSince = (int) $c['days_since_purchase'];

            // Score 0-100: weighted by amount owed, inactivity, and credit limit usage
            $score = 0;
            if ($balance > 0) {
                $score += min(50, ($balance / max($c['credit_limit'], 1)) * 50);
            }
            if ($daysSince >= 9999) {
                $score += 20; // never purchased
            } elseif ($daysSince > 60) {
                $score += 30;
            } elseif ($daysSince > 30) {
                $score += 15;
            }
            if ($balance > (float) $c['credit_limit'] && $c['credit_limit'] > 0) {
                $score += 20; // over credit limit
            }

            $reason = [];
            if ($balance > 0) $reason[] = "solde dû de " . number_format($balance, 2) . " DT";
            if ($daysSince >= 9999) {
                $reason[] = "aucun achat enregistré";
            } elseif ($daysSince > 30) {
                $reason[] = "inactif depuis $daysSince jours";
            }
            if ($balance > (float) $c['credit_limit'] && $c['credit_limit'] > 0) {
                $reason[] = "dépassement du plafond de crédit";
            }

            $results[] = [
                'customer_id' => $c['id'],
                'name' => $c['name'],
                'phone' => $c['phone'],
                'balance' => round($balance, 2),
                'days_since_purchase' => $daysSince >= 9999 ? null : $daysSince,
                'score' => (int) round(min(100, $score)),
                'reason' => empty($reason) ? 'aucune action requise' : implode(', ', $reason),
            ];
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return $results;
    }
}