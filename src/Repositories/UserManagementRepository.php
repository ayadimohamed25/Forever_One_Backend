<?php
namespace App\Repositories;
use App\Config\Database;
use App\Core\Uuid;

class UserManagementRepository {
    public function findAllByTenant(string $tenantId, ?string $search = null): array {
        $pdo = Database::connect();

        $sql = 'SELECT id, email, full_name, phone, role, is_active, last_login_at, created_at
                FROM users WHERE tenant_id = ?';
        $params = [$tenantId];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (email LIKE ? OR full_name LIKE ? OR phone LIKE ?)';
            $like = '%' . trim($search) . '%';
            $params = array_merge($params, [$like, $like, $like]);
        }

        $sql .= ' ORDER BY is_active DESC, full_name ASC, email ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(string $tenantId, string $id): ?array {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT id, email, full_name, phone, role, is_active, last_login_at, created_at
             FROM users WHERE tenant_id = ? AND id = ?'
        );
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function emailExists(string $email, ?string $exceptId = null): bool {
        $pdo = Database::connect();
        $sql = 'SELECT COUNT(*) FROM users WHERE email = ?';
        $params = [$email];

        if ($exceptId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(string $tenantId, array $data): array {
        $pdo = Database::connect();
        $id = Uuid::generate();

        $stmt = $pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, password_hash, full_name, phone, role, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $tenantId,
            $data['email'],
            password_hash($data['password'], PASSWORD_BCRYPT),
            $data['full_name'] ?? null,
            $data['phone'] ?? null,
            $data['role'],
            isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
        ]);

        return [
            'id' => $id,
            'email' => $data['email'],
            'full_name' => $data['full_name'] ?? null,
            'role' => $data['role'],
        ];
    }

    public function update(string $tenantId, string $id, array $data): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'UPDATE users SET email = ?, full_name = ?, phone = ?, role = ?, is_active = ?
             WHERE id = ? AND tenant_id = ?'
        );
        return $stmt->execute([
            $data['email'],
            $data['full_name'] ?? null,
            $data['phone'] ?? null,
            $data['role'],
            isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
            $id, $tenantId,
        ]);
    }

    public function changePassword(string $tenantId, string $id, string $newPassword): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?'
        );
        return $stmt->execute([
            password_hash($newPassword, PASSWORD_BCRYPT),
            $id, $tenantId,
        ]);
    }

    public function verifyPassword(string $id, string $password): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $hash = $stmt->fetchColumn();
        return $hash && password_verify($password, $hash);
    }

    public function touchLastLogin(string $id): void {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    /// Users are deactivated rather than deleted once they have activity,
    /// so the audit trail keeps pointing at a real person.
    public function hasActivity(string $id): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE user_id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function delete(string $tenantId, string $id): bool {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ? AND tenant_id = ?');
        return $stmt->execute([$id, $tenantId]);
    }

    public function countAdmins(string $tenantId, ?string $exceptId = null): int {
        $pdo = Database::connect();
        $sql = "SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'admin' AND is_active = 1";
        $params = [$tenantId];

        if ($exceptId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}