<?php
namespace App\Controllers;
use App\Repositories\WarehouseRepository;
use App\Services\AuditService;

class WarehouseController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('view_warehouses');
        $search = $_GET['search'] ?? null;
        echo json_encode((new WarehouseRepository())->findAllByTenant($claims['tenant_id'], $search));
    }

    public function store(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_warehouses');
        $data = $this->getJsonBody();

        if (empty($data['name'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Warehouse name is required']);
            return;
        }

        $warehouse = (new WarehouseRepository())->create($claims['tenant_id'], $data);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'create_warehouse', 'warehouse', $warehouse['id'], ['name' => $data['name']]);

        http_response_code(201);
        echo json_encode($warehouse);
    }

    public function update(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_warehouses');
        $data = $this->getJsonBody();

        if (empty($data['id']) || empty($data['name'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id and name are required']);
            return;
        }

        $repo = new WarehouseRepository();
        if (!$repo->findById($claims['tenant_id'], $data['id'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Warehouse not found']);
            return;
        }

        $repo->update($claims['tenant_id'], $data['id'], $data);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'update_warehouse', 'warehouse', $data['id'], ['name' => $data['name']]);

        echo json_encode(['status' => 'updated']);
    }

    public function destroy(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_warehouses');
        $data = $this->getJsonBody();

        if (empty($data['id'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id is required']);
            return;
        }

        $repo = new WarehouseRepository();
        $warehouse = $repo->findById($claims['tenant_id'], $data['id']);
        if (!$warehouse) {
            http_response_code(404);
            echo json_encode(['error' => 'Warehouse not found']);
            return;
        }

        if (!$repo->canDelete($claims['tenant_id'], $data['id'])) {
            http_response_code(409);
            echo json_encode(['error' => 'WAREHOUSE_IN_USE']);
            return;
        }

        $repo->delete($claims['tenant_id'], $data['id']);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'delete_warehouse', 'warehouse', $data['id'], ['name' => $warehouse['name']]);

        echo json_encode(['status' => 'deleted']);
    }
}