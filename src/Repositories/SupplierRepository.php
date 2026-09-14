<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class SupplierRepository {
    /// Enriches every supplier with their commercial situation and delivery performance.
    private function baseSelect(): string {
        return "SELECT s.*,
                       COALESCE((
                           SELECT SUM(p.total) FROM purchases p WHERE p.supplier_id = s.id
                       ), 0) AS total_purchases,
                       COALESCE((
                           SELECT SUM(pay.amount)
                           FROM payments pay
                           JOIN purchases p2 ON p2.id = pay.purchase_id
                           WHERE p2.supplier_id = s.id
                       ), 0) AS total_paid,
                       (SELECT COUNT(*) FROM purchases p3 WHERE p3.supplier_id = s.id) AS order_count,
                       (SELECT MAX(p4.created_at) FROM purchases p4 WHERE p4.supplier_id = s.id) AS last_purchase,
                       (SELECT COUNT(*) FROM purchases p5
                        WHERE p5.supplier_id = s.id
                          AND p5.expected_date IS NOT NULL
                          AND p5.received_date IS NOT NULL) AS tracked_deliveries,
                       (SELECT COUNT(*) FROM purchases p6
                        WHERE p6.supplier_id = s.id
                          AND p6.expected_date IS NOT NULL
                          AND p6.received_date IS NOT NULL
                          AND p6.received_date <= p6.expected_date) AS on_time_deliveries
                FROM suppliers s
                WHERE s.tenant_id = ?";
    }

    public function findAllByTenant(string $tenantId, ?string $search = null): array {
        $pdo = Database::connect();

        $sql = $this->baseSelect();
        $params = [$tenantId];

        if ($search !== null && trim($search) !== '') {
            $sql .= " AND (s.name LIKE ? OR s.phone LIKE ? OR s.email LIKE ? OR s.contact_person LIKE ?)";
            $like = '%' . trim($search) . '%';
            $params = array_merge($params, [$like, $like, $like, $like]);
        }

        $sql .= " ORDER BY s.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(string $tenantId, string $id): ?array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare($this->baseSelect() . " AND s.id = ?");
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function purchaseHistory(string $tenantId, string $supplierId, int $limit = 20): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            "SELECT p.id, p.reference, p.total, p.status, p.created_at,
                    p.expected_date, p.received_date,
                    COALESCE((SELECT SUM(pay.amount) FROM payments pay WHERE pay.purchase_id = p.id), 0) AS paid
             FROM purchases p
             WHERE p.tenant_id = ? AND p.supplier_id = ?
             ORDER BY p.created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $tenantId);
        $stmt->bindValue(2, $supplierId);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $id = Uuid::generate();
        $stmt = $pdo->prepare(
            'INSERT INTO suppliers
             (id, tenant_id, name, phone, email, address, tax_id, contact_person,
              payment_terms_days, bank_account, notes, lead_time_days)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $tenantId, $data['name'],
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null,
            $data['tax_id'] ?? null,
            $data['contact_person'] ?? null,
            $data['payment_terms_days'] ?? 0,
            $data['bank_account'] ?? null,
            $data['notes'] ?? null,
            $data['lead_time_days'] ?? 0,
        ]);
        return ['id' => $id] + $data;
    }

    public function update(string $tenantId, string $id, array $data): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'UPDATE suppliers
             SET name = ?, phone = ?, email = ?, address = ?, tax_id = ?,
                 contact_person = ?, payment_terms_days = ?, bank_account = ?,
                 notes = ?, lead_time_days = ?
             WHERE id = ? AND tenant_id = ?'
        );
        return $stmt->execute([
            $data['name'],
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null,
            $data['tax_id'] ?? null,
            $data['contact_person'] ?? null,
            $data['payment_terms_days'] ?? 0,
            $data['bank_account'] ?? null,
            $data['notes'] ?? null,
            $data['lead_time_days'] ?? 0,
            $id, $tenantId,
        ]);
    }

    public function canDelete(string $tenantId, string $id): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchases WHERE supplier_id = ? AND tenant_id = ?');
        $stmt->execute([$id, $tenantId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    public function delete(string $tenantId, string $id): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('DELETE FROM suppliers WHERE id = ? AND tenant_id = ?');
        return $stmt->execute([$id, $tenantId]);
    }
}