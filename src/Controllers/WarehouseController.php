<?php
namespace App\Controllers;
use App\Repositories\WarehouseRepository;

class WarehouseController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $warehouses = (new WarehouseRepository())->findAllByTenant($claims['tenant_id']);
        echo json_encode($warehouses);
    }
}