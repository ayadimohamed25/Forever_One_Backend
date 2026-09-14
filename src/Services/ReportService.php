<?php
namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class ReportService {
    private array $t;

    private const TRANSLATIONS = [
        'en' => [
            'title' => 'Director report',
            'generatedOn' => 'Generated on',
            'revenue' => 'Revenue',
            'receivables' => 'Receivables',
            'payables' => 'Payables',
            'stockAlerts' => 'Stock alerts',
            'stockSection' => 'Stock and forecasts',
            'product' => 'Product',
            'stock' => 'Stock',
            'coverage' => 'Coverage',
            'toOrder' => 'To order',
            'status' => 'Status',
            'outOfStock' => 'OUT OF STOCK',
            'soon' => 'Soon',
            'ok' => 'OK',
            'salesSection' => 'Recent sales',
            'date' => 'Date',
            'customer' => 'Customer',
            'amount' => 'Amount',
            'followUpSection' => 'Customers to follow up',
            'balanceDue' => 'Balance due',
            'score' => 'Score',
            'reason' => 'Reason',
            'noFollowUp' => 'No customers to follow up.',
            'footer' => 'Forever One &middot; Automatically generated report',
            'days' => 'd',
        ],
        'fr' => [
            'title' => 'Rapport dirigeant',
            'generatedOn' => 'Généré le',
            'revenue' => 'Chiffre d\'affaires',
            'receivables' => 'Créances',
            'payables' => 'Dettes',
            'stockAlerts' => 'Alertes stock',
            'stockSection' => 'Stock et prévisions',
            'product' => 'Produit',
            'stock' => 'Stock',
            'coverage' => 'Couverture',
            'toOrder' => 'À commander',
            'status' => 'Statut',
            'outOfStock' => 'RUPTURE',
            'soon' => 'Bientôt',
            'ok' => 'OK',
            'salesSection' => 'Ventes récentes',
            'date' => 'Date',
            'customer' => 'Client',
            'amount' => 'Montant',
            'followUpSection' => 'Clients à relancer',
            'balanceDue' => 'Solde dû',
            'score' => 'Score',
            'reason' => 'Motif',
            'noFollowUp' => 'Aucun client à relancer.',
            'footer' => 'Forever One &middot; Rapport généré automatiquement',
            'days' => 'j',
        ],
    ];

    public function generateDirectorReport(array $data, string $locale = 'en'): string {
        $this->t = self::TRANSLATIONS[$locale] ?? self::TRANSLATIONS['en'];

        $html = $this->buildHtml($data);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function buildHtml(array $d): string {
        $t = $this->t;
        $date = date('d/m/Y');
        $company = htmlspecialchars($d['company_name']);

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #222; }
  h1 { font-size: 20px; margin-bottom: 2px; }
  .subtitle { color: #777; font-size: 10px; margin-bottom: 20px; }
  h2 { font-size: 13px; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-top: 22px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th { background: #f2f2f2; text-align: left; padding: 6px; font-size: 10px; }
  td { padding: 6px; border-bottom: 1px solid #eee; }
  .kpi-row { width: 100%; margin-top: 10px; }
  .kpi { display: inline-block; width: 21%; padding: 10px; background: #f8f8f8; margin-right: 4px; vertical-align: top; }
  .kpi-label { font-size: 9px; color: #777; text-transform: uppercase; }
  .kpi-value { font-size: 16px; font-weight: bold; }
  .critical { color: #c00; font-weight: bold; }
  .warning { color: #e67e00; }
  .footer { margin-top: 30px; font-size: 9px; color: #999; text-align: center; }
</style></head><body>

<h1>{$t['title']}</h1>
<div class="subtitle">$company &middot; {$t['generatedOn']} $date</div>

<div class="kpi-row">
  <div class="kpi"><div class="kpi-label">{$t['revenue']}</div><div class="kpi-value">{$d['revenue']} DT</div></div>
  <div class="kpi"><div class="kpi-label">{$t['receivables']}</div><div class="kpi-value">{$d['receivables']} DT</div></div>
  <div class="kpi"><div class="kpi-label">{$t['payables']}</div><div class="kpi-value">{$d['payables']} DT</div></div>
  <div class="kpi"><div class="kpi-label">{$t['stockAlerts']}</div><div class="kpi-value">{$d['low_stock_count']}</div></div>
</div>

<h2>{$t['stockSection']}</h2>
<table>
  <tr><th>{$t['product']}</th><th>{$t['stock']}</th><th>{$t['coverage']}</th><th>{$t['toOrder']}</th><th>{$t['status']}</th></tr>
HTML;

        foreach ($d['stock_forecast'] as $s) {
            $name = htmlspecialchars($s['name']);
            $coverage = $s['days_of_coverage'] !== null
                ? $s['days_of_coverage'] . ' ' . $t['days']
                : '—';
            $class = $s['urgency'] === 'critical' ? 'critical' : ($s['urgency'] === 'warning' ? 'warning' : '');
            $label = $s['urgency'] === 'critical'
                ? $t['outOfStock']
                : ($s['urgency'] === 'warning' ? $t['soon'] : $t['ok']);
            $order = $s['suggested_order'] > 0 ? $s['suggested_order'] : '—';
            $html .= "<tr><td>$name</td><td>{$s['current_stock']}</td><td>$coverage</td><td>$order</td><td class=\"$class\">$label</td></tr>";
        }

        $html .= "</table><h2>{$t['salesSection']}</h2><table><tr><th>{$t['date']}</th><th>{$t['customer']}</th><th>{$t['amount']}</th><th>{$t['status']}</th></tr>";

        foreach ($d['recent_sales'] as $s) {
            $customer = htmlspecialchars($s['customer_name']);
            $html .= "<tr><td>{$s['created_at']}</td><td>$customer</td><td>{$s['total']} DT</td><td>{$s['status']}</td></tr>";
        }

        $html .= "</table><h2>{$t['followUpSection']}</h2><table><tr><th>{$t['customer']}</th><th>{$t['balanceDue']}</th><th>{$t['score']}</th><th>{$t['reason']}</th></tr>";

        $anyToChase = false;
        foreach ($d['customer_scores'] as $c) {
            if ($c['score'] <= 0) continue;
            $anyToChase = true;
            $name = htmlspecialchars($c['name']);
            $reason = htmlspecialchars($c['reason']);
            $html .= "<tr><td>$name</td><td>{$c['balance']} DT</td><td>{$c['score']}</td><td>$reason</td></tr>";
        }
        if (!$anyToChase) {
            $html .= "<tr><td colspan=\"4\">{$t['noFollowUp']}</td></tr>";
        }

        $html .= "</table><div class=\"footer\">{$t['footer']}</div></body></html>";

        return $html;
    }
}