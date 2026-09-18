<?php
namespace App\Controllers;
use App\Config\Database;
use App\Core\Permissions;
use App\Repositories\UserManagementRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\JwtService;

class AuthController extends BaseController {
    public function login(): void {
        header('Content-Type: application/json');
        $data = $this->getJsonBody();

        if (empty($data['email']) || empty($data['password'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Email and password are required']);
            return;
        }

        $user = (new UserRepository())->findByEmail($data['email']);

        if (!$user || !password_verify($data['password'], $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
            return;
        }

        // A deactivated account can no longer sign in.
        if (isset($user['is_active']) && (int) $user['is_active'] === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'ACCOUNT_DISABLED']);
            return;
        }

        $token = JwtService::generate([
            'user_id' => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'role' => $user['role'],
        ]);

        (new UserManagementRepository())->touchLastLogin($user['id']);

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT name FROM tenants WHERE id = ?');
        $stmt->execute([$user['tenant_id']]);
        $companyName = $stmt->fetchColumn() ?: '';

        AuditService::log($user['tenant_id'], $user['id'], 'login', 'user', $user['id']);

        echo json_encode([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'full_name' => $user['full_name'] ?? null,
                'role' => $user['role'],
                'tenant_id' => $user['tenant_id'],
                'company_name' => $companyName,
                'permissions' => Permissions::forRole($user['role']),
            ],
        ]);
    }
}