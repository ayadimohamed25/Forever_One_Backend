<?php
$base = '/forever-one-backend/public/index.php/api/v1';

$router->post("$base/auth/login", [App\Controllers\AuthController::class, 'login']);

$router->get("$base/products", [App\Controllers\ProductController::class, 'index']);
$router->post("$base/products", [App\Controllers\ProductController::class, 'store']);

$router->get("$base/warehouses", [App\Controllers\WarehouseController::class, 'index']);

$router->post("$base/stock/movements", [App\Controllers\StockController::class, 'storeMovement']);
$router->get("$base/customers", [App\Controllers\CustomerController::class, 'index']);
$router->post("$base/customers", [App\Controllers\CustomerController::class, 'store']);

$router->get("$base/suppliers", [App\Controllers\SupplierController::class, 'index']);
$router->post("$base/suppliers", [App\Controllers\SupplierController::class, 'store']);

$router->get("$base/sales", [App\Controllers\SaleController::class, 'index']);
$router->post("$base/sales", [App\Controllers\SaleController::class, 'store']);

$router->get("$base/purchases", [App\Controllers\PurchaseController::class, 'index']);
$router->post("$base/purchases", [App\Controllers\PurchaseController::class, 'store']);

$router->post("$base/payments", [App\Controllers\PaymentController::class, 'store']);
$router->get("$base/payments/sale-balance", [App\Controllers\PaymentController::class, 'saleBalance']);
$router->get("$base/payments/purchase-balance", [App\Controllers\PaymentController::class, 'purchaseBalance']);

$router->get("$base/dashboard", [App\Controllers\DashboardController::class, 'summary']);

$router->post("$base/documents/scan", [App\Controllers\DocumentController::class, 'scan']);
$router->post("$base/documents/confirm", [App\Controllers\DocumentController::class, 'confirm']);
$router->get("$base/documents", [App\Controllers\DocumentController::class, 'index']);

$router->post("$base/ai/chat", [App\Controllers\AiController::class, 'chat']);
$router->get("$base/ai/history", [App\Controllers\AiController::class, 'history']);