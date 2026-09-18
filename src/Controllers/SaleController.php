<?php
namespace App\Controllers;
use App\Repositories\SaleRepository;
use App\Services\AuditService;

class SaleController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('view_sales');
        $search = $_GET['search'] ?? null;
        echo json_encode((new SaleRepository())->findAllByTenant($claims['tenant_id'], $search));
    }

    public function show(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('view_sales');
        $id = $_GET['id'] ?? '';

        $repo = new SaleRepository();
        $sale = $repo->findById($claims['tenant_id'], $id);

        if (!$sale) {
            http_response_code(404);
            echo json_encode(['error' => 'Sale not found']);
            return;
        }

        echo json_encode([
            'sale' => $sale,
            'lines' => $repo->lines($id),
        ]);
    }

    public function store(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_sales');
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
            AuditService::log($claims['tenant_id'], $claims['user_id'], 'create_sale', 'sale', $sale['id'], ['total' => $sale['total']]);
            http_response_code(201);
            echo json_encode($sale);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create sale']);
        }
    }

    public function update(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_sales');
        $data = $this->getJsonBody();

        if (empty($data['id']) || empty($data['lines'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id and lines are required']);
            return;
        }

        $repo = new SaleRepository();
        if (!$repo->findById($claims['tenant_id'], $data['id'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Sale not found']);
            return;
        }

        // Editing a sale that has payments would desynchronise the balance.
        if ($repo->hasPayments($data['id'])) {
            http_response_code(409);
            echo json_encode(['error' => 'SALE_HAS_PAYMENTS']);
            return;
        }

        try {
            $repo->update($claims['tenant_id'], $data['id'], $data);
            AuditService::log($claims['tenant_id'], $claims['user_id'], 'update_sale', 'sale', $data['id']);
            echo json_encode(['status' => 'updated']);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update sale']);
        }
    }

    public function destroy(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_sales');
        $data = $this->getJsonBody();

        if (empty($data['id'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id is required']);
            return;
        }

        $repo = new SaleRepository();
        $sale = $repo->findById($claims['tenant_id'], $data['id']);
        if (!$sale) {
            http_response_code(404);
            echo json_encode(['error' => 'Sale not found']);
            return;
        }

        if ($repo->hasPayments($data['id'])) {
            http_response_code(409);
            echo json_encode(['error' => 'SALE_HAS_PAYMENTS']);
            return;
        }

        try {
            $repo->delete($claims['tenant_id'], $data['id']);
            AuditService::log($claims['tenant_id'], $claims['user_id'], 'delete_sale', 'sale', $data['id'], ['total' => $sale['total']]);
            echo json_encode(['status' => 'deleted']);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete sale']);
        }
    }
}