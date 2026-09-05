<?php
namespace App\Repositories;
use App\Config\Database;

class AiContextRepository {
    public function buildContext(string $tenantId): string {
        $pdo = Database::connect();
        $context = "Tu es un assistant métier pour une PME. Réponds uniquement à partir des données ci-dessous. ";
        $context .= "Si l'information n'est pas dans les données, dis-le clairement au lieu d'inventer. ";
        $context .= "Sois concis et concret.\n\n=== DONNÉES DE L'ENTREPRISE ===\n\n";

        // Products with current stock
        $stmt = $pdo->prepare(
            "SELECT p.name, p.price, p.cost, p.min_threshold,
                    COALESCE(SUM(CASE WHEN sm.type='in' THEN sm.quantity
                                      WHEN sm.type='out' THEN -sm.quantity ELSE 0 END), 0) as stock
             FROM products p
             LEFT JOIN stock_movements sm ON sm.product_id = p.id
             WHERE p.tenant_id = ?
             GROUP BY p.id, p.name, p.price, p.cost, p.min_threshold"
        );
        $stmt->execute([$tenantId]);
        $context .= "PRODUITS ET STOCK :\n";
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $alert = $p['stock'] <= $p['min_threshold'] ? ' [ALERTE RUPTURE]' : '';
            $context .= "- {$p['name']} : stock {$p['stock']}, seuil {$p['min_threshold']}, prix {$p['price']}, coût {$p['cost']}$alert\n";
        }

        // Customers with outstanding balance
        $stmt = $pdo->prepare(
            "SELECT c.name, c.phone, c.credit_limit,
                    COALESCE(SUM(s.total), 0) as total_sales,
                    COALESCE((SELECT SUM(pay.amount) FROM payments pay
                              JOIN sales s2 ON s2.id = pay.sale_id
                              WHERE s2.customer_id = c.id), 0) as total_paid
             FROM customers c
             LEFT JOIN sales s ON s.customer_id = c.id
             WHERE c.tenant_id = ?
             GROUP BY c.id, c.name, c.phone, c.credit_limit"
        );
        $stmt->execute([$tenantId]);
        $context .= "\nCLIENTS :\n";
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $balance = $c['total_sales'] - $c['total_paid'];
            $context .= "- {$c['name']} (tél {$c['phone']}) : achats {$c['total_sales']}, payé {$c['total_paid']}, reste dû {$balance}, plafond crédit {$c['credit_limit']}\n";
        }

        // Suppliers
        $stmt = $pdo->prepare('SELECT name, phone, lead_time_days FROM suppliers WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $context .= "\nFOURNISSEURS :\n";
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $s) {
            $context .= "- {$s['name']} (tél {$s['phone']}), délai livraison {$s['lead_time_days']} jours\n";
        }

        // Recent sales
        $stmt = $pdo->prepare(
            "SELECT s.total, s.status, s.created_at, c.name as customer_name
             FROM sales s JOIN customers c ON c.id = s.customer_id
             WHERE s.tenant_id = ? ORDER BY s.created_at DESC LIMIT 10"
        );
        $stmt->execute([$tenantId]);
        $context .= "\nVENTES RÉCENTES (10 dernières) :\n";
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $s) {
            $context .= "- {$s['created_at']} : {$s['customer_name']}, {$s['total']} DT, statut {$s['status']}\n";
        }

        // Recent purchases
        $stmt = $pdo->prepare(
            "SELECT p.total, p.status, p.created_at, su.name as supplier_name
             FROM purchases p JOIN suppliers su ON su.id = p.supplier_id
             WHERE p.tenant_id = ? ORDER BY p.created_at DESC LIMIT 10"
        );
        $stmt->execute([$tenantId]);
        $context .= "\nACHATS RÉCENTS (10 derniers) :\n";
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $context .= "- {$p['created_at']} : {$p['supplier_name']}, {$p['total']} DT, statut {$p['status']}\n";
        }

        return $context;
    }
}