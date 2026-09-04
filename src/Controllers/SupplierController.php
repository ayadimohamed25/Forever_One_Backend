<?php
namespace App\Controllers;
use App\Repositories\SupplierRepository;

class SupplierController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        echo json_encode((new SupplierRepository())->findAllByTenant($claims['tenant_id']));
    }

    public function store(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        if (empty($data['name'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Supplier name is required']);
            return;
        }

        $supplier = (new SupplierRepository())->create($claims['tenant_id'], $data);
        http_response_code(201);
        echo json_encode($supplier);
    }
}