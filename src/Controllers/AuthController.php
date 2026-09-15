<?php
namespace App\Controllers;
use App\Repositories\UserRepository;
use App\Services\JwtService;
use App\Services\AuditService;

class AuthController {
    public function login(): void {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);

        $user = (new UserRepository())->findByEmail($data['email'] ?? '');

        if (!$user || !password_verify($data['password'] ?? '', $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
            return;
        }

        $token = JwtService::generate([
            'user_id' => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'role' => $user['role'],
        ]);

        AuditService::log($user['tenant_id'], $user['id'], 'login', 'user', $user['id']);

                // Include the company name so the app can show it without another round trip.
        $pdo = \App\Config\Database::connect();
        $stmt = $pdo->prepare('SELECT name FROM tenants WHERE id = ?');
        $stmt->execute([$user['tenant_id']]);
        $companyName = $stmt->fetchColumn() ?: '';

        echo json_encode([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'tenant_id' => $user['tenant_id'],
                'company_name' => $companyName,
            ],
        ]);
    }
}