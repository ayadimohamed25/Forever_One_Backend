<?php
namespace App\Controllers;
use App\Repositories\SaleRepository;

class SaleController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        echo json_encode((new SaleRepository())->findAllByTenant($claims['tenant_id']));
    }

    public function store(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        foreach (['customer_id', 'warehouse_id', 'lines'] as $field) {
            if (empty($data[$field])) {
                http_response_code(422);
                echo json_encode(['error' => "Field '$field' is required"]);
                return;
            }
        }

        if (!is_array($data['lines']) || count($data['lines']) === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'At least one sale line is required']);
            return;
        }

        try {
            $sale = (new SaleRepository())->create($claims['tenant_id'], $data);
            http_response_code(201);
            echo json_encode($sale);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create sale']);
        }
    }
}