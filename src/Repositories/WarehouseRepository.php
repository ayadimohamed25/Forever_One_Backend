<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class WarehouseRepository {
    /// Adds the stock each warehouse currently holds and its value at cost,
    /// so the list is informative rather than just a set of names.
    private function baseSelect(): string {
        return "SELECT w.*,
                       COALESCE((
                           SELECT SUM(CASE WHEN sm.type='in'  THEN sm.quantity
                                           WHEN sm.type='out' THEN -sm.quantity
                                           ELSE 0 END)
                           FROM stock_movements sm WHERE sm.warehouse_id = w.id
                       ), 0) AS total_units,
                       COALESCE((
                           SELECT SUM(
                               CASE WHEN sm2.type='in'  THEN sm2.quantity
                                    WHEN sm2.type='out' THEN -sm2.quantity
                                    ELSE 0 END * p.cost)
                           FROM stock_movements sm2
                           JOIN products p ON p.id = sm2.product_id
                           WHERE sm2.warehouse_id = w.id
                       ), 0) AS stock_value,
                       (SELECT COUNT(DISTINCT sm3.product_id)
                        FROM stock_movements sm3 WHERE sm3.warehouse_id = w.id) AS product_count
                FROM warehouses w
                WHERE w.tenant_id = ?";
    }

    public function findAllByTenant(string $tenantId, ?string $search = null): array {
        $pdo = Database::connect();

        $sql = $this->baseSelect();
        $params = [$tenantId];

        if ($search !== null && trim($search) !== '') {
            $sql .= " AND (w.name LIKE ? OR w.code LIKE ? OR w.location LIKE ?)";
            $like = '%' . trim($search) . '%';
            $params = array_merge($params, [$like, $like, $like]);
        }

        $sql .= " ORDER BY w.is_active DESC, w.name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(string $tenantId, string $id): ?array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare($this->baseSelect() . " AND w.id = ?");
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function values(array $d): array {
        return [
            $d['code'] ?? null,
            $d['name'],
            $d['location'] ?? null,
            $d['address'] ?? null,
            $d['manager_name'] ?? null,
            $d['phone'] ?? null,
            isset($d['is_active']) ? (int) (bool) $d['is_active'] : 1,
            $d['notes'] ?? null,
        ];
    }

    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $id = Uuid::generate();

        $stmt = $pdo->prepare(
            'INSERT INTO warehouses
             (id, tenant_id, code, name, location, address, manager_name, phone, is_active, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array_merge([$id, $tenantId], $this->values($data)));

        return ['id' => $id] + $data;
    }

    public function update(string $tenantId, string $id, array $data): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'UPDATE warehouses
             SET code = ?, name = ?, location = ?, address = ?,
                 manager_name = ?, phone = ?, is_active = ?, notes = ?
             WHERE id = ? AND tenant_id = ?'
        );
        return $stmt->execute(array_merge($this->values($data), [$id, $tenantId]));
    }

    /// A warehouse that has ever held stock keeps its movement history,
    /// so it is never deleted.
    public function canDelete(string $tenantId, string $id): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM stock_movements WHERE warehouse_id = ? AND tenant_id = ?'
        );
        $stmt->execute([$id, $tenantId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    public function delete(string $tenantId, string $id): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('DELETE FROM warehouses WHERE id = ? AND tenant_id = ?');
        return $stmt->execute([$id, $tenantId]);
    }
}