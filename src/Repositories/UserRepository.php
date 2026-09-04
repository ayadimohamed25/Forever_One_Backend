<?php
namespace App\Repositories;
use App\Config\Database;

class UserRepository {
    public function findByEmail(string $email): ?array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $user ?: null;
    }
}