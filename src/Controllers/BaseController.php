<?php
namespace App\Controllers;
use App\Services\JwtService;

abstract class BaseController {
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