<?php
namespace App\Controllers;
use App\Repositories\CustomerRepository;

class CustomerController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        echo json_encode((new CustomerRepository())->findAllByTenant($claims['tenant_id']));
    }

    public function store(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        if (empty($data['name'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Customer name is required']);
            return;
        }

        $customer = (new CustomerRepository())->create($claims['tenant_id'], $data);
        http_response_code(201);
        echo json_encode($customer);
    }
}