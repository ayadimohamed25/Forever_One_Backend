<?php
namespace App\Controllers;
use App\Config\Database;
use App\Core\Permissions;
use App\Core\Uuid;
use App\Services\AuditService;
use App\Services\JwtService;

class RegisterController extends BaseController {
    /// Public endpoint: a business signs up for Forever One.
    /// It creates a brand-new tenant with its own empty data, so no
    /// existing company's information is ever exposed.
    public function register(): void {
        header('Content-Type: application/json');
        $data = $this->getJsonBody();

        foreach (['company_name', 'full_name', 'email', 'password'] as $field) {
            if (empty($data[$field])) {
                http_response_code(422);
                echo json_encode(['error' => "Field '$field' is required"]);
                return;
            }
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['error' => 'INVALID_EMAIL']);
            return;
        }

        if (strlen($data['password']) < 8) {
            http_response_code(422);
            echo json_encode(['error' => 'PASSWORD_TOO_SHORT']);
            return;
        }

        $pdo = Database::connect();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute([$data['email']]);
        if ((int) $stmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'EMAIL_TAKEN']);
            return;
        }

        $pdo->beginTransaction();

        try {
            $tenantId = Uuid::generate();
            $userId = Uuid::generate();
            $warehouseId = Uuid::generate();

            $stmt = $pdo->prepare('INSERT INTO tenants (id, name) VALUES (?, ?)');
            $stmt->execute([$tenantId, $data['company_name']]);

            // The person who registers the company becomes its administrator.
            $stmt = $pdo->prepare(
                'INSERT INTO users (id, tenant_id, email, password_hash, full_name, phone, role, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([
                $userId, $tenantId,
                $data['email'],
                password_hash($data['password'], PASSWORD_BCRYPT),
                $data['full_name'],
                $data['phone'] ?? null,
                'admin',
            ]);

            // A first warehouse, so the app is usable straight away instead
            // of blocking on "you must create a warehouse first".
            $stmt = $pdo->prepare(
                'INSERT INTO warehouses (id, tenant_id, name, location, is_active)
                 VALUES (?, ?, ?, ?, 1)'
            );
            $stmt->execute([
                $warehouseId, $tenantId,
                $data['warehouse_name'] ?? 'Dépôt principal',
                $data['city'] ?? null,
            ]);

            // A default category so products can be filed immediately.
            $stmt = $pdo->prepare(
                'INSERT INTO categories (id, tenant_id, name, description, color)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                Uuid::generate(), $tenantId,
                'Général', 'Produits divers', '#6C4BF4',
            ]);

            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'REGISTRATION_FAILED']);
            return;
        }

        $token = JwtService::generate([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'role' => 'admin',
        ]);

        AuditService::log($tenantId, $userId, 'register_company', 'tenant', $tenantId, ['company' => $data['company_name']]);

        http_response_code(201);
        echo json_encode([
            'token' => $token,
            'user' => [
                'id' => $userId,
                'email' => $data['email'],
                'full_name' => $data['full_name'],
                'role' => 'admin',
                'tenant_id' => $tenantId,
                'company_name' => $data['company_name'],
                'permissions' => Permissions::forRole('admin'),
            ],
        ]);
    }
}