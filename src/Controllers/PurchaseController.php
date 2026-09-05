<?php
namespace App\Controllers;
use App\Repositories\PurchaseRepository;
use App\Services\AuditService;

class PurchaseController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        echo json_encode((new PurchaseRepository())->findAllByTenant($claims['tenant_id']));
    }

    public function store(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        foreach (['supplier_id', 'warehouse_id', 'lines'] as $field) {
            if (empty($data[$field])) {
                http_response_code(422);
                echo json_encode(['error' => "Field '$field' is required"]);
                return;
            }
        }

        if (!is_array($data['lines']) || count($data['lines']) === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'At least one purchase line is required']);
            return;
        }

        try {
            $purchase = (new PurchaseRepository())->create($claims['tenant_id'], $data);
            AuditService::log($claims['tenant_id'], $claims['user_id'], 'create_purchase', 'purchase', $purchase['id'], ['total' => $purchase['total']]);
            http_response_code(201);
            echo json_encode($purchase);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create purchase']);
        }
    }
}