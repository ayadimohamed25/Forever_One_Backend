<?php
namespace App\Controllers;
use App\Repositories\CategoryRepository;
use App\Services\AuditService;

class CategoryController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        echo json_encode((new CategoryRepository())->findAllByTenant($claims['tenant_id']));
    }

    public function store(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        if (empty($data['name'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Category name is required']);
            return;
        }

        $category = (new CategoryRepository())->create($claims['tenant_id'], $data);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'create_category', 'category', $category['id'], ['name' => $data['name']]);

        http_response_code(201);
        echo json_encode($category);
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

        $repo = new CategoryRepository();
        if (!$repo->findById($claims['tenant_id'], $data['id'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Category not found']);
            return;
        }

        $repo->update($claims['tenant_id'], $data['id'], $data);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'update_category', 'category', $data['id'], ['name' => $data['name']]);

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

        $repo = new CategoryRepository();
        $category = $repo->findById($claims['tenant_id'], $data['id']);
        if (!$category) {
            http_response_code(404);
            echo json_encode(['error' => 'Category not found']);
            return;
        }

        if (!$repo->canDelete($claims['tenant_id'], $data['id'])) {
            http_response_code(409);
            echo json_encode(['error' => 'CATEGORY_IN_USE']);
            return;
        }

        $repo->delete($claims['tenant_id'], $data['id']);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'delete_category', 'category', $data['id'], ['name' => $category['name']]);

        echo json_encode(['status' => 'deleted']);
    }
}