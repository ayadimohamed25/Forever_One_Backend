<?php
namespace App\Services;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService {
    public static function generate(array $claims): string {
        $payload = array_merge($claims, ['iat' => time(), 'exp' => time() + 3600]);
        return JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
    }

    public static function verify(string $token): array {
        return (array) JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
    }
}