<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class DocumentRepository {
    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $id = Uuid::generate();
        $stmt = $pdo->prepare(
            'INSERT INTO documents (id, tenant_id, image_path, raw_text, extracted_amount, extracted_date, confidence)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $tenantId, $data['image_path'], $data['raw_text'],
            $data['extracted_amount'], $data['extracted_date'], $data['confidence'],
        ]);
        return ['id' => $id] + $data;
    }

    public function confirm(string $tenantId, string $id, float $amount, ?string $date): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            "UPDATE documents SET status = 'confirmed', confirmed_amount = ?, confirmed_date = ?
             WHERE id = ? AND tenant_id = ?"
        );
        return $stmt->execute([$amount, $date, $id, $tenantId]);
    }

    public function findAllByTenant(string $tenantId): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE tenant_id = ? ORDER BY created_at DESC');
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}