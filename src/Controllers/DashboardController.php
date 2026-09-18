<?php
namespace App\Controllers;
use App\Repositories\DashboardRepository;

class DashboardController extends BaseController {
    public function summary(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('view_dashboard');
        echo json_encode((new DashboardRepository())->getSummary($claims['tenant_id']));
    }
}