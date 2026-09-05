<?php
namespace App\Controllers;

use App\Config\Database;
use App\Repositories\DashboardRepository;
use App\Repositories\PredictionRepository;
use App\Services\AuditService;
use App\Services\ReportService;

class ReportController extends BaseController {
    public function directorReport(): void {
        $claims = $this->authenticate();
        $tenantId = $claims['tenant_id'];

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT name FROM tenants WHERE id = ?');
        $stmt->execute([$tenantId]);
        $companyName = $stmt->fetchColumn() ?: 'Entreprise';

        $summary = (new DashboardRepository())->getSummary($tenantId);
        $predictions = new PredictionRepository();

        $stmt = $pdo->prepare(
            "SELECT s.total, s.status, s.created_at, c.name as customer_name
             FROM sales s JOIN customers c ON c.id = s.customer_id
             WHERE s.tenant_id = ? ORDER BY s.created_at DESC LIMIT 15"
        );
        $stmt->execute([$tenantId]);
        $recentSales = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $data = [
            'company_name' => $companyName,
            'revenue' => number_format($summary['revenue'], 2),
            'receivables' => number_format($summary['receivables'], 2),
            'payables' => number_format($summary['payables'], 2),
            'low_stock_count' => $summary['low_stock_count'],
            'stock_forecast' => $predictions->stockForecast($tenantId),
            'customer_scores' => $predictions->customerScoring($tenantId),
            'recent_sales' => $recentSales,
        ];

        $pdf = (new ReportService())->generateDirectorReport($data);

        AuditService::log($tenantId, $claims['user_id'], 'generate_report', 'report', null, ['type' => 'director']);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="rapport-dirigeant-' . date('Y-m-d') . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }
}