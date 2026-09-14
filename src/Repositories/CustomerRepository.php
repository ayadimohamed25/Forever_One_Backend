<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class CustomerRepository {
    /// Base SELECT that enriches every customer with their commercial situation.
    private function baseSelect(): string {
        return "SELECT c.*,
                       COALESCE(SUM(DISTINCT_SALES.total), 0) AS total_purchases,
                       COALESCE((
                           SELECT SUM(pay.amount)
                           FROM payments pay
                           JOIN sales s2 ON s2.id = pay.sale_id
                           WHERE s2.customer_id = c.id
                       ), 0) AS total_paid,
                       (SELECT MAX(s3.created_at) FROM sales s3 WHERE s3.customer_id = c.id) AS last_purchase,
                       (SELECT COUNT(*) FROM sales s4 WHERE s4.customer_id = c.id) AS order_count
                FROM customers c
                LEFT JOIN (
                    SELECT id, customer_id, total FROM sales
                ) AS DISTINCT_SALES ON DISTINCT_SALES.customer_id = c.id
                WHERE c.tenant_id = ?";
    }

    public function findAllByTenant(string $tenantId, ?string $search = null): array {
        $pdo = Database::connect();

        $sql = $this->baseSelect();
        $params = [$tenantId];

        if ($search !== null && trim($search) !== '') {
            $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
            $like = '%' . trim($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= " GROUP BY c.id ORDER BY c.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(string $tenantId, string $id): ?array {
        $pdo = Database::connect();
        $sql = $this->baseSelect() . " AND c.id = ? GROUP BY c.id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /// The customer's past sales, most recent first.
    public function salesHistory(string $tenantId, string $customerId, int $limit = 20): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            "SELECT s.id, s.total, s.status, s.created_at,
                    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.sale_id = s.id), 0) AS paid
             FROM sales s
             WHERE s.tenant_id = ? AND s.customer_id = ?
             ORDER BY s.created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $tenantId);
        $stmt->bindValue(2, $customerId);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $id = Uuid::generate();
        $stmt = $pdo->prepare(
            'INSERT INTO customers
             (id, tenant_id, name, phone, email, address, tax_id, customer_type,
              payment_terms_days, notes, credit_limit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $tenantId, $data['name'],
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null,
            $data['tax_id'] ?? null,
            in_array($data['customer_type'] ?? 'company', ['individual', 'company'])
                ? $data['customer_type'] : 'company',
            $data['payment_terms_days'] ?? 0,
            $data['notes'] ?? null,
            $data['credit_limit'] ?? 0,
        ]);
        return ['id' => $id] + $data;
    }

    public function update(string $tenantId, string $id, array $data): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'UPDATE customers
             SET name = ?, phone = ?, email = ?, address = ?, tax_id = ?,
                 customer_type = ?, payment_terms_days = ?, notes = ?, credit_limit = ?
             WHERE id = ? AND tenant_id = ?'
        );
        return $stmt->execute([
            $data['name'],
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null,
            $data['tax_id'] ?? null,
            in_array($data['customer_type'] ?? 'company', ['individual', 'company'])
                ? $data['customer_type'] : 'company',
            $data['payment_terms_days'] ?? 0,
            $data['notes'] ?? null,
            $data['credit_limit'] ?? 0,
            $id, $tenantId,
        ]);
    }

    /// A customer with sales history is never deleted — that would orphan invoices.
    public function canDelete(string $tenantId, string $id): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE customer_id = ? AND tenant_id = ?');
        $stmt->execute([$id, $tenantId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    public function delete(string $tenantId, string $id): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('DELETE FROM customers WHERE id = ? AND tenant_id = ?');
        return $stmt->execute([$id, $tenantId]);
    }
}