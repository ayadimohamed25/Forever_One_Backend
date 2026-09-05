<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class AiConversationRepository {
    public function create(string $tenantId, string $userId, string $question, string $answer): array {
        $pdo = Database::connect();
        $id = Uuid::generate();
        $stmt = $pdo->prepare(
            'INSERT INTO ai_conversations (id, tenant_id, user_id, question, answer) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $tenantId, $userId, $question, $answer]);
        return ['id' => $id, 'question' => $question, 'answer' => $answer];
    }

    public function findAllByTenant(string $tenantId): array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT * FROM ai_conversations WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 50'
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}