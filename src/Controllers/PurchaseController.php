<?php
namespace App\Controllers;
use App\Repositories\PurchaseRepository;
use App\Services\AuditService;


class PurchaseController extends BaseController {
       public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $search = $_GET['search'] ?? null;
        echo json_encode((new PurchaseRepository())->findAllByTenant($claims['tenant_id'], $search));
    }

    public function show(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $id = $_GET['id'] ?? '';

        $repo = new PurchaseRepository();
        $purchase = $repo->findById($claims['tenant_id'], $id);

        if (!$purchase) {
            http_response_code(404);
            echo json_encode(['error' => 'Purchase not found']);
            return;
        }

        echo json_encode([
            'purchase' => $purchase,
            'lines' => $repo->lines($id),
        ]);
    }

    public function update(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        if (empty($data['id']) || empty($data['lines'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id and lines are required']);
            return;
        }

        $repo = new PurchaseRepository();
        if (!$repo->findById($claims['tenant_id'], $data['id'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Purchase not found']);
            return;
        }

        if ($repo->hasPayments($data['id'])) {
            http_response_code(409);
            echo json_encode(['error' => 'PURCHASE_HAS_PAYMENTS']);
            return;
        }

        try {
            $repo->update($claims['tenant_id'], $data['id'], $data);
            AuditService::log($claims['tenant_id'], $claims['user_id'], 'update_purchase', 'purchase', $data['id']);
            echo json_encode(['status' => 'updated']);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update purchase']);
        }
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

        $repo = new PurchaseRepository();
        $purchase = $repo->findById($claims['tenant_id'], $data['id']);
        if (!$purchase) {
            http_response_code(404);
            echo json_encode(['error' => 'Purchase not found']);
            return;
        }

        if ($repo->hasPayments($data['id'])) {
            http_response_code(409);
            echo json_encode(['error' => 'PURCHASE_HAS_PAYMENTS']);
            return;
        }

        try {
            $repo->delete($claims['tenant_id'], $data['id']);
            AuditService::log($claims['tenant_id'], $claims['user_id'], 'delete_purchase', 'purchase', $data['id'], ['total' => $purchase['total']]);
            echo json_encode(['status' => 'deleted']);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete purchase']);
        }
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
    public function receive(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        if (empty($data['id'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id is required']);
            return;
        }

        $ok = (new PurchaseRepository())->markReceived(
            $claims['tenant_id'],
            $data['id'],
            $data['received_date'] ?? null
        );

        if (!$ok) {
            http_response_code(409);
            echo json_encode(['error' => 'PURCHASE_ALREADY_RECEIVED']);
            return;
        }

        AuditService::log($claims['tenant_id'], $claims['user_id'], 'receive_purchase', 'purchase', $data['id']);
        echo json_encode(['status' => 'received']);
    }
}