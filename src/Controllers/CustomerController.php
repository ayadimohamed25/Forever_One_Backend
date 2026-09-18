<?php
namespace App\Controllers;
use App\Repositories\CustomerRepository;
use App\Services\AuditService;

class CustomerController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('view_customers');
        $search = $_GET['search'] ?? null;
        echo json_encode((new CustomerRepository())->findAllByTenant($claims['tenant_id'], $search));
    }

    public function show(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('view_customers');
        $id = $_GET['id'] ?? '';

        $repo = new CustomerRepository();
        $customer = $repo->findById($claims['tenant_id'], $id);

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['error' => 'Customer not found']);
            return;
        }

        echo json_encode([
            'customer' => $customer,
            'sales' => $repo->salesHistory($claims['tenant_id'], $id),
        ]);
    }

    public function store(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_customers');
        $data = $this->getJsonBody();

        if (empty($data['name'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Customer name is required']);
            return;
        }

        $customer = (new CustomerRepository())->create($claims['tenant_id'], $data);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'create_customer', 'customer', $customer['id'], ['name' => $data['name']]);

        http_response_code(201);
        echo json_encode($customer);
    }

    public function update(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_customers');
        $data = $this->getJsonBody();

        if (empty($data['id']) || empty($data['name'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id and name are required']);
            return;
        }

        $repo = new CustomerRepository();
        if (!$repo->findById($claims['tenant_id'], $data['id'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Customer not found']);
            return;
        }

        $repo->update($claims['tenant_id'], $data['id'], $data);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'update_customer', 'customer', $data['id'], ['name' => $data['name']]);

        echo json_encode(['status' => 'updated']);
    }

    public function destroy(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_customers');
        $data = $this->getJsonBody();

        if (empty($data['id'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id is required']);
            return;
        }

        $repo = new CustomerRepository();
        $customer = $repo->findById($claims['tenant_id'], $data['id']);
        if (!$customer) {
            http_response_code(404);
            echo json_encode(['error' => 'Customer not found']);
            return;
        }

        if (!$repo->canDelete($claims['tenant_id'], $data['id'])) {
            http_response_code(409);
            echo json_encode(['error' => 'CUSTOMER_IN_USE']);
            return;
        }

        $repo->delete($claims['tenant_id'], $data['id']);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'delete_customer', 'customer', $data['id'], ['name' => $customer['name']]);

        echo json_encode(['status' => 'deleted']);
    }
}