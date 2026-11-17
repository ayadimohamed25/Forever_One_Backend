<?php
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Development CORS: the Flutter debug run (flutter run -d chrome) serves the
// app from http://localhost:<random port>, a different origin from this API,
// so the browser blocks every call ("Couldn't reach the server"). Outside
// production, any localhost / 127.0.0.1 origin on any port may call the API.
// The deployed web app (same origin) never needs this. Set APP_ENV=production
// in .env on a real server to switch it off.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isProduction = strtolower($_ENV['APP_ENV'] ?? 'development') === 'production';
if (!$isProduction && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Accept-Language, X-Requested-With');
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Access-Control-Max-Age: 600');
    // The browser's preflight: answer it here, before routing.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

$router = new App\Core\Router();
require __DIR__ . '/../src/routes.php';
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);