<?php
namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class ReportService {
    public function generateDirectorReport(array $data): string {
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

<h1>Rapport dirigeant</h1>
<div class="subtitle">$company &middot; Généré le $date</div>

<div class="kpi-row">
  <div class="kpi"><div class="kpi-label">Chiffre d'affaires</div><div class="kpi-value">{$d['revenue']} DT</div></div>
  <div class="kpi"><div class="kpi-label">Créances</div><div class="kpi-value">{$d['receivables']} DT</div></div>
  <div class="kpi"><div class="kpi-label">Dettes</div><div class="kpi-value">{$d['payables']} DT</div></div>
  <div class="kpi"><div class="kpi-label">Alertes stock</div><div class="kpi-value">{$d['low_stock_count']}</div></div>
</div>

<h2>Stock et prévisions</h2>
<table>
  <tr><th>Produit</th><th>Stock</th><th>Couverture</th><th>À commander</th><th>Statut</th></tr>
HTML;

        foreach ($d['stock_forecast'] as $s) {
            $name = htmlspecialchars($s['name']);
            $coverage = $s['days_of_coverage'] !== null ? $s['days_of_coverage'] . ' j' : '—';
            $class = $s['urgency'] === 'critical' ? 'critical' : ($s['urgency'] === 'warning' ? 'warning' : '');
            $label = $s['urgency'] === 'critical' ? 'RUPTURE' : ($s['urgency'] === 'warning' ? 'Bientôt' : 'OK');
            $order = $s['suggested_order'] > 0 ? $s['suggested_order'] : '—';
            $html .= "<tr><td>$name</td><td>{$s['current_stock']}</td><td>$coverage</td><td>$order</td><td class=\"$class\">$label</td></tr>";
        }

        $html .= '</table><h2>Ventes récentes</h2><table><tr><th>Date</th><th>Client</th><th>Montant</th><th>Statut</th></tr>';

        foreach ($d['recent_sales'] as $s) {
            $customer = htmlspecialchars($s['customer_name']);
            $html .= "<tr><td>{$s['created_at']}</td><td>$customer</td><td>{$s['total']} DT</td><td>{$s['status']}</td></tr>";
        }

        $html .= '</table><h2>Clients à relancer</h2><table><tr><th>Client</th><th>Solde dû</th><th>Score</th><th>Motif</th></tr>';

        $anyToChase = false;
        foreach ($d['customer_scores'] as $c) {
            if ($c['score'] <= 0) continue;
            $anyToChase = true;
            $name = htmlspecialchars($c['name']);
            $reason = htmlspecialchars($c['reason']);
            $html .= "<tr><td>$name</td><td>{$c['balance']} DT</td><td>{$c['score']}</td><td>$reason</td></tr>";
        }
        if (!$anyToChase) {
            $html .= '<tr><td colspan="4">Aucun client à relancer.</td></tr>';
        }

        $html .= '</table><div class="footer">Forever One &middot; Rapport généré automatiquement</div></body></html>';

        return $html;
    }
}