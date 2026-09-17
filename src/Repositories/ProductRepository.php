<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class ProductRepository {
    /// Joins the category and default supplier so the UI can show names,
    /// and computes current stock from movements.
    private function baseSelect(): string {
        return "SELECT p.*,
                       cat.name  AS category_name,
                       cat.color AS category_color,
                       sup.name  AS supplier_name,
                       COALESCE(SUM(CASE WHEN sm.type='in'  THEN sm.quantity
                                         WHEN sm.type='out' THEN -sm.quantity
                                         ELSE 0 END), 0) AS current_stock
                FROM products p
                LEFT JOIN categories cat ON cat.id = p.category_id
                LEFT JOIN suppliers  sup ON sup.id = p.default_supplier_id
                LEFT JOIN stock_movements sm ON sm.product_id = p.id
                WHERE p.tenant_id = ?";
    }

    public function findAllByTenant(
        string $tenantId,
        ?string $search = null,
        ?string $categoryId = null,
        bool $onlyActive = false
    ): array {
        $pdo = Database::connect();

        $sql = $this->baseSelect();
        $params = [$tenantId];

        if ($search !== null && trim($search) !== '') {
            $sql .= " AND (p.name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ?)";
            $like = '%' . trim($search) . '%';
            $params = array_merge($params, [$like, $like, $like]);
        }

        if ($categoryId !== null && $categoryId !== '') {
            $sql .= " AND p.category_id = ?";
            $params[] = $categoryId;
        }

        if ($onlyActive) {
            $sql .= " AND p.is_active = 1";
        }

        $sql .= " GROUP BY p.id ORDER BY p.is_active DESC, p.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(string $tenantId, string $id): ?array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare($this->baseSelect() . " AND p.id = ? GROUP BY p.id");
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /// Stock movements for one product, so the detail page can show history.
    public function stockHistory(string $tenantId, string $productId, int $limit = 20): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            "SELECT sm.id, sm.type, sm.quantity, sm.note, sm.created_at,
                    w.name AS warehouse_name
             FROM stock_movements sm
             LEFT JOIN warehouses w ON w.id = sm.warehouse_id
             WHERE sm.tenant_id = ? AND sm.product_id = ?
             ORDER BY sm.created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $tenantId);
        $stmt->bindValue(2, $productId);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function columns(): array {
        return [
            'sku', 'category_id', 'default_supplier_id', 'name', 'description',
            'barcode', 'price', 'cost', 'vat_rate', 'min_threshold',
            'max_threshold', 'shelf_location', 'is_active', 'notes',
            'unit', 'purchase_unit', 'units_per_purchase',
        ];
    }

    private function values(array $d): array {
        return [
            $d['sku'] ?? null,
            !empty($d['category_id']) ? $d['category_id'] : null,
            !empty($d['default_supplier_id']) ? $d['default_supplier_id'] : null,
            $d['name'],
            $d['description'] ?? null,
            $d['barcode'] ?? null,
            $d['price'] ?? 0,
            $d['cost'] ?? 0,
            $d['vat_rate'] ?? 19,
            $d['min_threshold'] ?? 0,
            isset($d['max_threshold']) && $d['max_threshold'] !== '' ? $d['max_threshold'] : null,
            $d['shelf_location'] ?? null,
            isset($d['is_active']) ? (int) (bool) $d['is_active'] : 1,
            $d['notes'] ?? null,
            $d['unit'] ?? 'unit',
            $d['purchase_unit'] ?? null,
            $d['units_per_purchase'] ?? 1,
        ];
    }

    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $id = Uuid::generate();

        $cols = $this->columns();
        $placeholders = implode(', ', array_fill(0, count($cols) + 2, '?'));
        $columnList = 'id, tenant_id, ' . implode(', ', $cols);

        $stmt = $pdo->prepare("INSERT INTO products ($columnList) VALUES ($placeholders)");
        $stmt->execute(array_merge([$id, $tenantId], $this->values($data)));

        return ['id' => $id] + $data;
    }

    public function update(string $tenantId, string $id, array $data): bool {
        $pdo = Database::connect();

        $assignments = implode(' = ?, ', $this->columns()) . ' = ?';
        $stmt = $pdo->prepare(
            "UPDATE products SET $assignments WHERE id = ? AND tenant_id = ?"
        );

        return $stmt->execute(array_merge($this->values($data), [$id, $tenantId]));
    }

    public function canDelete(string $tenantId, string $id): bool {
        $pdo = Database::connect();

        foreach ([
            ['SELECT COUNT(*) FROM sale_lines WHERE product_id = ?', [$id]],
            ['SELECT COUNT(*) FROM purchase_lines WHERE product_id = ?', [$id]],
            ['SELECT COUNT(*) FROM stock_movements WHERE product_id = ? AND tenant_id = ?', [$id, $tenantId]],
        ] as [$sql, $params]) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
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