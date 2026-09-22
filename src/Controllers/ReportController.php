<?php
namespace App\Controllers;

use App\Config\Database;
use App\Repositories\DashboardRepository;
use App\Repositories\PredictionRepository;
use App\Services\AuditService;
use App\Services\ReportService;

class ReportController extends BaseController {
    public function directorReport(): void {
        $claims = $this->authorize('view_reports');
        $tenantId = $claims['tenant_id'];
        $locale = in_array($_GET['locale'] ?? 'en', ['en', 'fr'], true) ? $_GET['locale'] : 'en';

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT name FROM tenants WHERE id = ?');
        $stmt->execute([$tenantId]);
        $companyName = $stmt->fetchColumn() ?: 'Forever One';

        $kpis = (new DashboardRepository())->getSummary($tenantId)['kpis'];
        $predictions = new PredictionRepository();

        // Paid amount included so the report can show paid / unpaid status.
        $stmt = $pdo->prepare(
            "SELECT s.total, s.created_at, c.name AS customer_name,
                    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.sale_id = s.id), 0) AS paid
             FROM sales s JOIN customers c ON c.id = s.customer_id
             WHERE s.tenant_id = ? ORDER BY s.created_at DESC LIMIT 15"
        );
        $stmt->execute([$tenantId]);
        $recentSales = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $data = [
            'company_name' => $companyName,
            'revenue' => (float) $kpis['revenue_total'],
            'receivables' => (float) $kpis['receivables'],
            'payables' => (float) $kpis['payables'],
            'low_stock_count' => (int) $kpis['low_stock_count'],
            'stock_forecast' => $predictions->stockForecast($tenantId),
            'customer_scores' => $predictions->customerScoring($tenantId),
            'recent_sales' => $recentSales,
        ];

        $pdf = (new ReportService())->generateDirectorReport($data, $locale);

        AuditService::log($tenantId, $claims['user_id'], 'generate_report', 'report', null, ['type' => 'director']);

        $filename = ($locale === 'fr' ? 'rapport-dirigeant-' : 'director-report-') . date('Y-m-d') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }
}