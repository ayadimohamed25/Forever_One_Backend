<?php
namespace App\Controllers;
use App\Services\JwtService;

abstract class BaseController {
    /// Authenticates and checks the permission in one step.
    /// Sends 403 and stops the request when the role is not allowed.
    protected function authorize(string $permission): array {
        $claims = $this->authenticate();
        $role = $claims['role'] ?? '';

        if (!\App\Core\Permissions::can($role, $permission)) {
            http_response_code(403);
            echo json_encode(['error' => 'FORBIDDEN']);
            exit;
        }

        return $claims;
    }
    protected function authenticate(): array {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        $token = str_replace('Bearer ', '', $authHeader);

        if (!$token) {
            http_response_code(401);
            echo json_encode(['error' => 'Missing authentication token']);
            exit;
        }

        try {
            return JwtService::verify($token);
        } catch (\Exception $e) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or expired token']);
            exit;
        }
    }

    protected function getJsonBody(): array {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }
}