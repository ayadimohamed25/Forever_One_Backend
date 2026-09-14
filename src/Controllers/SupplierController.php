<?php
namespace App\Controllers;
use App\Repositories\SupplierRepository;
use App\Services\AuditService;

class SupplierController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $search = $_GET['search'] ?? null;
        echo json_encode((new SupplierRepository())->findAllByTenant($claims['tenant_id'], $search));
    }

    public function show(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $id = $_GET['id'] ?? '';

        $repo = new SupplierRepository();
        $supplier = $repo->findById($claims['tenant_id'], $id);

        if (!$supplier) {
            http_response_code(404);
            echo json_encode(['error' => 'Supplier not found']);
            return;
        }

        echo json_encode([
            'supplier' => $supplier,
            'purchases' => $repo->purchaseHistory($claims['tenant_id'], $id),
        ]);
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
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'create_supplier', 'supplier', $supplier['id'], ['name' => $data['name']]);

        http_response_code(201);
        echo json_encode($supplier);
    }

    public function update(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        if (empty($data['id']) || empty($data['name'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id and name are required']);
            return;
        }

        $repo = new SupplierRepository();
        if (!$repo->findById($claims['tenant_id'], $data['id'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Supplier not found']);
            return;
        }

        $repo->update($claims['tenant_id'], $data['id'], $data);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'update_supplier', 'supplier', $data['id'], ['name' => $data['name']]);

        echo json_encode(['status' => 'updated']);
    }

    public function destroy(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        if (empty($data['id'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id is required']);
            return;
        }

        $repo = new SupplierRepository();
        $supplier = $repo->findById($claims['tenant_id'], $data['id']);
        if (!$supplier) {
            http_response_code(404);
            echo json_encode(['error' => 'Supplier not found']);
            return;
        }

        if (!$repo->canDelete($claims['tenant_id'], $data['id'])) {
            http_response_code(409);
            echo json_encode(['error' => 'SUPPLIER_IN_USE']);
            return;
        }

        $repo->delete($claims['tenant_id'], $data['id']);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'delete_supplier', 'supplier', $data['id'], ['name' => $supplier['name']]);

        echo json_encode(['status' => 'deleted']);
    }
}