<?php
namespace App\Controllers;
use App\Repositories\ProductRepository;

class ProductController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $products = (new ProductRepository())->findAllByTenant($claims['tenant_id']);
        echo json_encode($products);
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
        http_response_code(201);
        echo json_encode($product);
    }
}