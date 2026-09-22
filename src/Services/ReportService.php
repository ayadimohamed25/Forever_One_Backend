<?php
namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/// Renders the director report as a PDF. Its text comes from the EN/FR table
/// below, chosen by the language currently selected in the app.
class ReportService {
    private array $t = [];

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
            'days' => 'days',
            'salesSection' => 'Recent sales',
            'date' => 'Date',
            'customer' => 'Customer',
            'amount' => 'Amount',
            'paid' => 'Paid',
            'partiallyPaid' => 'Partially paid',
            'unpaid' => 'Unpaid',
            'followUpSection' => 'Customers to follow up',
            'balanceDue' => 'Balance due',
            'score' => 'Score',
            'reason' => 'Reason',
            'reasonOwes' => 'Owes %s',
            'reasonInactive' => 'No purchase for %d days',
            'reasonNever' => 'No purchase recorded',
            'reasonOverLimit' => 'Over credit limit',
            'reasonNone' => 'No action needed',
            'noFollowUp' => 'No customers to follow up.',
            'noData' => 'No data.',
            'footer' => 'Forever One · Automatically generated report',
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
            'days' => 'jours',
            'salesSection' => 'Ventes récentes',
            'date' => 'Date',
            'customer' => 'Client',
            'amount' => 'Montant',
            'paid' => 'Payé',
            'partiallyPaid' => 'Partiellement payé',
            'unpaid' => 'Impayé',
            'followUpSection' => 'Clients à relancer',
            'balanceDue' => 'Solde dû',
            'score' => 'Score',
            'reason' => 'Motif',
            'reasonOwes' => 'Doit %s',
            'reasonInactive' => 'Aucun achat depuis %d jours',
            'reasonNever' => 'Aucun achat enregistré',
            'reasonOverLimit' => 'Plafond de crédit dépassé',
            'reasonNone' => 'Aucune action requise',
            'noFollowUp' => 'Aucun client à relancer.',
            'noData' => 'Aucune donnée.',
            'footer' => 'Forever One · Rapport généré automatiquement',
        ],
    ];

    public function generateDirectorReport(array $data, string $locale = 'en'): string {
        $this->t = self::TRANSLATIONS[$locale] ?? self::TRANSLATIONS['en'];

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->buildHtml($data), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function buildHtml(array $d): string {
        $t = $this->t;
        $css = $this->css();
        $company = $this->e($d['company_name']);
        $today = date('d/m/Y');

        $receivables = (float) $d['receivables'];
        $alerts = (int) $d['low_stock_count'];

        $revenueText = $this->dt((float) $d['revenue']);
        $receivablesText = $this->dt($receivables);
        $payablesText = $this->dt((float) $d['payables']);
        $receivablesClass = $receivables > 0.009 ? 'kpi-value text-danger' : 'kpi-value';
        $alertsClass = $alerts > 0 ? 'kpi-value text-danger' : 'kpi-value';

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>{$css}</style></head><body>

<div class="band">
  <table><tr>
    <td style="width:52px"><div class="logo">&#8734;</div></td>
    <td>
      <div class="brand">Forever One</div>
      <div class="band-sub">{$t['title']}</div>
    </td>
    <td style="text-align:right">
      <div class="company">{$company}</div>
      <div class="band-sub">{$t['generatedOn']} {$today}</div>
    </td>
  </tr></table>
</div>

<div class="content">

<table class="kpis"><tr>
  <td class="kpi"><div class="kpi-label">{$t['revenue']}</div><div class="kpi-value">{$revenueText}</div></td>
  <td class="kpi"><div class="kpi-label">{$t['receivables']}</div><div class="{$receivablesClass}">{$receivablesText}</div></td>
  <td class="kpi"><div class="kpi-label">{$t['payables']}</div><div class="kpi-value">{$payablesText}</div></td>
  <td class="kpi"><div class="kpi-label">{$t['stockAlerts']}</div><div class="{$alertsClass}">{$alerts}</div></td>
</tr></table>

HTML;

        // ── Stock ──
        $html .= "<h2>{$t['stockSection']}</h2>";
        $html .= "<table class=\"data\"><tr><th>{$t['product']}</th><th class=\"num\">{$t['stock']}</th>"
            . "<th class=\"num\">{$t['coverage']}</th><th class=\"num\">{$t['toOrder']}</th><th>{$t['status']}</th></tr>";

        if (empty($d['stock_forecast'])) {
            $html .= "<tr><td colspan=\"5\" class=\"muted\">{$t['noData']}</td></tr>";
        }
        foreach ($d['stock_forecast'] as $s) {
            [$label, $class] = $this->stockStatus($s);
            $coverage = $s['days_of_coverage'] !== null
                ? (int) $s['days_of_coverage'] . ' ' . $t['days']
                : '—';
            $order = (int) $s['suggested_order'] > 0 ? (int) $s['suggested_order'] : '—';

            $html .= '<tr>'
                . '<td>' . $this->e($s['name']) . '</td>'
                . '<td class="num">' . (int) $s['current_stock'] . '</td>'
                . '<td class="num">' . $coverage . '</td>'
                . '<td class="num">' . $order . '</td>'
                . '<td><span class="pill ' . $class . '">' . $label . '</span></td>'
                . '</tr>';
        }
        $html .= '</table>';

        // ── Recent sales ──
        $html .= "<h2>{$t['salesSection']}</h2>";
        $html .= "<table class=\"data\"><tr><th>{$t['date']}</th><th>{$t['customer']}</th>"
            . "<th class=\"num\">{$t['amount']}</th><th>{$t['status']}</th></tr>";

        if (empty($d['recent_sales'])) {
            $html .= "<tr><td colspan=\"4\" class=\"muted\">{$t['noData']}</td></tr>";
        }
        foreach ($d['recent_sales'] as $s) {
            [$label, $class] = $this->paymentStatus((float) $s['total'], (float) $s['paid']);
            $html .= '<tr>'
                . '<td>' . $this->day($s['created_at']) . '</td>'
                . '<td>' . $this->e($s['customer_name']) . '</td>'
                . '<td class="num">' . $this->dt((float) $s['total']) . '</td>'
                . '<td><span class="pill ' . $class . '">' . $label . '</span></td>'
                . '</tr>';
        }
        $html .= '</table>';

        // ── Customers to follow up ──
        $html .= "<h2>{$t['followUpSection']}</h2>";
        $html .= "<table class=\"data\"><tr><th>{$t['customer']}</th><th class=\"num\">{$t['balanceDue']}</th>"
            . "<th class=\"num\">{$t['score']}</th><th>{$t['reason']}</th></tr>";

        $anyToChase = false;
        foreach ($d['customer_scores'] as $c) {
            if ((int) $c['score'] <= 0) continue;
            $anyToChase = true;

            $balance = (float) $c['balance'];
            $balanceClass = $balance > 0.009 ? 'num text-danger' : 'num';

            $html .= '<tr>'
                . '<td>' . $this->e($c['name']) . '</td>'
                . '<td class="' . $balanceClass . '">' . $this->dt($balance) . '</td>'
                . '<td class="num">' . (int) $c['score'] . '</td>'
                . '<td>' . $this->e($this->followUpReason($c)) . '</td>'
                . '</tr>';
        }
        if (!$anyToChase) {
            $html .= "<tr><td colspan=\"4\" class=\"muted\">{$t['noFollowUp']}</td></tr>";
        }
        $html .= '</table>';

        $html .= "<div class=\"footer\">{$t['footer']}</div></div></body></html>";

        return $html;
    }

    /// Same rule as the app: 0 or less → out of stock, flagged → soon, else OK.
    private function stockStatus(array $s): array {
        if ((int) $s['current_stock'] <= 0) return [$this->t['outOfStock'], 'danger'];
        if (($s['urgency'] ?? 'ok') !== 'ok') return [$this->t['soon'], 'warning'];
        return [$this->t['ok'], 'success'];
    }

    private function paymentStatus(float $total, float $paid): array {
        if ($total - $paid <= 0.009) return [$this->t['paid'], 'success'];
        if ($paid > 0) return [$this->t['partiallyPaid'], 'warning'];
        return [$this->t['unpaid'], 'warning'];
    }

    private function followUpReason(array $c): string {
        $t = $this->t;
        $parts = [];

        $balance = (float) $c['balance'];
        if ($balance > 0.009) {
            $parts[] = sprintf($t['reasonOwes'], $this->dt($balance));
        }
        if (!empty($c['never_purchased'])) {
            $parts[] = $t['reasonNever'];
        } elseif ((int) ($c['days_since_purchase'] ?? 0) > 30) {
            $parts[] = sprintf($t['reasonInactive'], (int) $c['days_since_purchase']);
        }
        if (!empty($c['over_credit_limit'])) {
            $parts[] = $t['reasonOverLimit'];
        }

        return $parts ? implode(' · ', $parts) : $t['reasonNone'];
    }

    private function dt(float $value): string {
        return number_format($value, 2, '.', '') . ' DT';
    }

    private function day(?string $value): string {
        return $value ? date('d/m/Y', strtotime($value)) : '—';
    }

    private function e(?string $value): string {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    private function css(): string {
        return <<<'CSS'
@page { margin: 0; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #2A1F1A; margin: 0; }

.band { background: #2A1F1A; padding: 22px 36px; }
.band table { width: 100%; border-collapse: collapse; }
.band td { vertical-align: middle; padding: 0; }
.logo { width: 40px; height: 40px; line-height: 40px; background: #C8553D; border-radius: 11px;
        color: #FFFFFF; text-align: center; font-size: 22px; font-weight: bold; }
.brand { font-size: 17px; font-weight: bold; color: #FFFFFF; }
.company { font-size: 14px; font-weight: bold; color: #FFFFFF; }
.band-sub { font-size: 9.5px; color: #D9CFC6; margin-top: 3px; }

.content { padding: 26px 36px 30px 36px; }

.kpis { width: 100%; border-collapse: separate; border-spacing: 6px 0; }
.kpi { width: 25%; background: #F5F1EC; border-radius: 10px; padding: 12px; vertical-align: top; }
.kpi-label { font-size: 8.5px; color: #8C8378; text-transform: uppercase; letter-spacing: 0.4px; }
.kpi-value { font-size: 15px; font-weight: bold; margin-top: 5px; color: #2A1F1A; }

h2 { font-size: 13px; color: #C8553D; margin: 26px 0 8px 0; }

table.data { width: 100%; border-collapse: collapse; }
table.data th { background: #F5F1EC; color: #2A1F1A; text-align: left; padding: 7px 8px; font-size: 9.5px; }
table.data td { padding: 7px 8px; border-bottom: 1px solid #EAE3DA; vertical-align: middle; }
.num { text-align: right; white-space: nowrap; }

.pill { padding: 2px 8px; border-radius: 9px; font-size: 9px; font-weight: bold; white-space: nowrap; }
.danger { color: #B42318; background: #FBE4E1; }
.warning { color: #A16207; background: #FBF0D5; }
.success { color: #3F7D4E; background: #E5F0E7; }

.text-danger { color: #B42318; font-weight: bold; }
.muted { color: #8C8378; }
.footer { margin-top: 30px; font-size: 8.5px; color: #8C8378; text-align: center; }
CSS;
    }
}