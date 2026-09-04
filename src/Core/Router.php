<?php
namespace App\Core;

class Router {
    private array $routes = [];

    public function post(string $path, array $handler): void {
        $this->routes['POST'][$path] = $handler;
    }

    public function get(string $path, array $handler): void {
        $this->routes['GET'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): void {
        $path = parse_url($uri, PHP_URL_PATH);
        $handler = $this->routes[$method][$path] ?? null;

        if (!$handler) {
            http_response_code(404);
            echo json_encode(['error' => 'Route not found']);
            return;
        }

        [$class, $methodName] = $handler;
        (new $class())->$methodName();
    }
}