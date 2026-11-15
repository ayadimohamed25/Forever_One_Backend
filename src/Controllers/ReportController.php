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
        $locale = self::reportLanguage($_GET);

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
        // Lets anyone check, in the browser's Network tab, which language the
        // server actually used.
        header('Content-Language: ' . $locale);
        echo $pdf;
    }

    /// The report language is the one selected IN THE APP, sent explicitly
    /// as ?lang= (older app builds send ?locale=). The browser's or the OS's
    /// Accept-Language is deliberately ignored: a French Chrome must still get
    /// an English report when the app is set to English.
    public static function reportLanguage(array $query): string {
        foreach (['lang', 'locale'] as $key) {
            $value = strtolower(substr((string) ($query[$key] ?? ''), 0, 2));
            if (in_array($value, ['en', 'fr'], true)) return $value;
        }
        return 'en';
    }
}