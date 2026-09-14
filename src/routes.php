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

$router->get("$base/predictions/stock", [App\Controllers\PredictionController::class, 'stockForecast']);
$router->get("$base/predictions/dormant", [App\Controllers\PredictionController::class, 'dormantProducts']);
$router->get("$base/predictions/customers", [App\Controllers\PredictionController::class, 'customerScoring']);

$router->get("$base/audit", [App\Controllers\AuditController::class, 'index']);

$router->get("$base/reports/director", [App\Controllers\ReportController::class, 'directorReport']);

$router->get("$base/products/show", [App\Controllers\ProductController::class, 'show']);
$router->post("$base/products/update", [App\Controllers\ProductController::class, 'update']);
$router->post("$base/products/delete", [App\Controllers\ProductController::class, 'destroy']);

$router->get("$base/customers/show", [App\Controllers\CustomerController::class, 'show']);
$router->post("$base/customers/update", [App\Controllers\CustomerController::class, 'update']);
$router->post("$base/customers/delete", [App\Controllers\CustomerController::class, 'destroy']);

$router->get("$base/suppliers/show", [App\Controllers\SupplierController::class, 'show']);
$router->post("$base/suppliers/update", [App\Controllers\SupplierController::class, 'update']);
$router->post("$base/suppliers/delete", [App\Controllers\SupplierController::class, 'destroy']);