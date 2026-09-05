<?php
namespace App\Controllers;
use App\Repositories\PaymentRepository;
use App\Services\AuditService;

class PaymentController extends BaseController {
    public function store(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        if (empty($data['amount']) || (empty($data['sale_id']) && empty($data['purchase_id']))) {
            http_response_code(422);
            echo json_encode(['error' => 'amount and either sale_id or purchase_id are required']);
            return;
        }
        if (!empty($data['sale_id']) && !empty($data['purchase_id'])) {
            http_response_code(422);
            echo json_encode(['error' => 'A payment cannot target both a sale and a purchase']);
            return;
        }

        $repo = new PaymentRepository();
        $payment = $repo->create($claims['tenant_id'], $data);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'record_payment', 'payment', $payment['id'], ['amount' => $data['amount']]);

        $balance = !empty($data['sale_id'])
            ? $repo->getSaleBalance($claims['tenant_id'], $data['sale_id'])
            : $repo->getPurchaseBalance($claims['tenant_id'], $data['purchase_id']);

        http_response_code(201);
        echo json_encode(['payment' => $payment, 'balance' => $balance]);
    }

    public function saleBalance(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $saleId = $_GET['sale_id'] ?? '';
        echo json_encode((new PaymentRepository())->getSaleBalance($claims['tenant_id'], $saleId));
    }

    public function purchaseBalance(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $purchaseId = $_GET['purchase_id'] ?? '';
        echo json_encode((new PaymentRepository())->getPurchaseBalance($claims['tenant_id'], $purchaseId));
    }
}