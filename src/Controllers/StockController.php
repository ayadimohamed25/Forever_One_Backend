<?php
namespace App\Controllers;
use App\Repositories\StockMovementRepository;

class StockController extends BaseController {
    public function storeMovement(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        foreach (['product_id', 'warehouse_id', 'type', 'quantity'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                http_response_code(422);
                echo json_encode(['error' => "Field '$field' is required"]);
                return;
            }
        }

        $repo = new StockMovementRepository();
        $movement = $repo->create($claims['tenant_id'], $data);
        $currentStock = $repo->currentStock($claims['tenant_id'], $data['product_id'], $data['warehouse_id']);

        http_response_code(201);
        echo json_encode(['movement' => $movement, 'current_stock' => $currentStock]);
    }
}