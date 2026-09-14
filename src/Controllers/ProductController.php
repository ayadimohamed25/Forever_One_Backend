<?php
namespace App\Controllers;
use App\Repositories\ProductRepository;
use App\Services\AuditService;

class ProductController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $search = $_GET['search'] ?? null;
        $products = (new ProductRepository())->findAllByTenant($claims['tenant_id'], $search);
        echo json_encode($products);
    }

    public function show(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $id = $_GET['id'] ?? '';

        $product = (new ProductRepository())->findById($claims['tenant_id'], $id);
        if (!$product) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            return;
        }
        echo json_encode($product);
    }

    public function store(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        if (empty($data['name'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Product name is required']);
            return;
        }

        $product = (new ProductRepository())->create($claims['tenant_id'], $data);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'create_product', 'product', $product['id'], ['name' => $data['name']]);

        http_response_code(201);
        echo json_encode($product);
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

        $repo = new ProductRepository();
        if (!$repo->findById($claims['tenant_id'], $data['id'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            return;
        }

        $repo->update($claims['tenant_id'], $data['id'], $data);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'update_product', 'product', $data['id'], ['name' => $data['name']]);

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

        $repo = new ProductRepository();
        $product = $repo->findById($claims['tenant_id'], $data['id']);
        if (!$product) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            return;
        }

        if (!$repo->canDelete($claims['tenant_id'], $data['id'])) {
            http_response_code(409);
            echo json_encode(['error' => 'PRODUCT_IN_USE']);
            return;
        }

        $repo->delete($claims['tenant_id'], $data['id']);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'delete_product', 'product', $data['id'], ['name' => $product['name']]);

        echo json_encode(['status' => 'deleted']);
    }
}