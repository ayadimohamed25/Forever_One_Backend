<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class CategoryRepository {
    public function findAllByTenant(string $tenantId, ?string $search = null): array {
        $pdo = Database::connect();

        $sql = "SELECT c.*,
                       (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
                FROM categories c
                WHERE c.tenant_id = ?";
        $params = [$tenantId];

        if ($search !== null && trim($search) !== '') {
            $sql .= " AND (c.name LIKE ? OR c.description LIKE ?)";
            $like = '%' . trim($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= " ORDER BY c.name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(string $tenantId, string $id): ?array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $id = Uuid::generate();
        $stmt = $pdo->prepare(
            'INSERT INTO categories (id, tenant_id, name, description, color) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $tenantId, $data['name'],
            $data['description'] ?? null,
            $data['color'] ?? null,
        ]);
        return ['id' => $id] + $data;
    }

    public function update(string $tenantId, string $id, array $data): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'UPDATE categories SET name = ?, description = ?, color = ? WHERE id = ? AND tenant_id = ?'
        );
        return $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['color'] ?? null,
            $id, $tenantId,
        ]);
    }

    /// A category still holding products is never deleted — that would
    /// silently uncategorise the catalogue.
    public function canDelete(string $tenantId, string $id): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ? AND tenant_id = ?');
        $stmt->execute([$id, $tenantId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    public function delete(string $tenantId, string $id): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ? AND tenant_id = ?');
        return $stmt->execute([$id, $tenantId]);
    }
}