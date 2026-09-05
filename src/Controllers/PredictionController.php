<?php
namespace App\Controllers;
use App\Repositories\PredictionRepository;

class PredictionController extends BaseController {
    public function stockForecast(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        echo json_encode((new PredictionRepository())->stockForecast($claims['tenant_id']));
    }

    public function dormantProducts(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $days = isset($_GET['days']) ? (int) $_GET['days'] : 30;
        echo json_encode((new PredictionRepository())->dormantProducts($claims['tenant_id'], $days));
    }

    public function customerScoring(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        echo json_encode((new PredictionRepository())->customerScoring($claims['tenant_id']));
    }
}