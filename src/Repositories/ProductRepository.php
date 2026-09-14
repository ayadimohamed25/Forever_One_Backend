<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class ProductRepository {
    public function findAllByTenant(string $tenantId, ?string $search = null): array {
        $pdo = Database::connect();

        $sql = "SELECT p.*,
                       COALESCE(SUM(CASE WHEN sm.type='in' THEN sm.quantity
                                         WHEN sm.type='out' THEN -sm.quantity
                                         ELSE 0 END), 0) AS current_stock
                FROM products p
                LEFT JOIN stock_movements sm ON sm.product_id = p.id
                WHERE p.tenant_id = ?";

        $params = [$tenantId];

        if ($search !== null && trim($search) !== '') {
            $sql .= " AND (p.name LIKE ? OR p.barcode LIKE ?)";
            $like = '%' . trim($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= " GROUP BY p.id ORDER BY p.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(string $tenantId, string $id): ?array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            "SELECT p.*,
                    COALESCE(SUM(CASE WHEN sm.type='in' THEN sm.quantity
                                      WHEN sm.type='out' THEN -sm.quantity
                                      ELSE 0 END), 0) AS current_stock
             FROM products p
             LEFT JOIN stock_movements sm ON sm.product_id = p.id
             WHERE p.tenant_id = ? AND p.id = ?
             GROUP BY p.id"
        );
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $id = Uuid::generate();
        $stmt = $pdo->prepare(
            'INSERT INTO products (id, tenant_id, category_id, name, barcode, price, cost, min_threshold, unit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $tenantId,
            $data['category_id'] ?? null,
            $data['name'],
            $data['barcode'] ?? null,
            $data['price'] ?? 0,
            $data['cost'] ?? 0,
            $data['min_threshold'] ?? 0,
            $data['unit'] ?? 'unit',
        ]);
        return ['id' => $id] + $data;
    }

    public function update(string $tenantId, string $id, array $data): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'UPDATE products
             SET name = ?, barcode = ?, price = ?, cost = ?, min_threshold = ?, unit = ?
             WHERE id = ? AND tenant_id = ?'
        );
        return $stmt->execute([
            $data['name'],
            $data['barcode'] ?? null,
            $data['price'] ?? 0,
            $data['cost'] ?? 0,
            $data['min_threshold'] ?? 0,
            $data['unit'] ?? 'unit',
            $id, $tenantId,
        ]);
    }

    /// Returns false when the product is referenced by sales, purchases or
    /// stock movements — we never silently destroy business history.
    public function canDelete(string $tenantId, string $id): bool {
        $pdo = Database::connect();

        foreach ([
            'SELECT COUNT(*) FROM sale_lines WHERE product_id = ?',
            'SELECT COUNT(*) FROM purchase_lines WHERE product_id = ?',
            'SELECT COUNT(*) FROM stock_movements WHERE product_id = ? AND tenant_id = ?',
        ] as $i => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($i === 2 ? [$id, $tenantId] : [$id]);
            if ((int) $stmt->fetchColumn() > 0) return false;
        }

        return true;
    }

    public function delete(string $tenantId, string $id): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('DELETE FROM products WHERE id = ? AND tenant_id = ?');
        return $stmt->execute([$id, $tenantId]);
    }
}