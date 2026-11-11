<?php
namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/// Renders the director report as a PDF. Its text comes from the EN/FR table
/// below, chosen by the language currently selected in the app.
class ReportService {
    /// Long stock lists are cut here; the rest is summarised in one line.
    private const STOCK_ROWS = 10;

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
            'stockNote' => 'The 10 most critical products.',
            'andOthers' => 'and %d other products',
            'product' => 'Product',
            'stock' => 'Stock',
            'coverage' => 'Coverage',
            'toOrder' => 'To order',
            'status' => 'Status',
            'outOfStock' => 'Out of stock',
            'soon' => 'Low stock',
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
            'stockNote' => 'Les 10 produits les plus critiques.',
            'andOthers' => 'et %d autres produits',
            'product' => 'Produit',
            'stock' => 'Stock',
            'coverage' => 'Couverture',
            'toOrder' => 'À commander',
            'status' => 'Statut',
            'outOfStock' => 'Rupture',
            'soon' => 'Stock faible',
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

        // ── Stock: the most critical products first ──
        $forecast = $d['stock_forecast'];
        usort($forecast, fn($a, $b) => $this->criticality($a) <=> $this->criticality($b));
        $shown = array_slice($forecast, 0, self::STOCK_ROWS);
        $remaining = max(0, count($forecast) - count($shown));

        $html .= '<div class="section">';
        $html .= "<h2>{$t['stockSection']}</h2>";
        if ($remaining > 0) {
            $html .= "<p class=\"note\">{$t['stockNote']}</p>";
        }
        $html .= "<table class=\"data\"><thead><tr><th>{$t['product']}</th><th class=\"num\">{$t['stock']}</th>"
            . "<th class=\"num\">{$t['coverage']}</th><th class=\"num\">{$t['toOrder']}</th>"
            . "<th class=\"right\">{$t['status']}</th></tr></thead><tbody>";

        if (!$shown) {
            $html .= "<tr><td colspan=\"5\" class=\"muted\">{$t['noData']}</td></tr>";
        }
        foreach ($shown as $s) {
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
                . '<td class="right"><span class="pill ' . $class . '">' . $label . '</span></td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';

        if ($remaining > 0) {
            $html .= '<p class="note">' . sprintf($t['andOthers'], $remaining) . '</p>';
        }
        $html .= '</div>';

        // ── Recent sales ──
        $html .= '<div class="section">';
        $html .= "<h2>{$t['salesSection']}</h2>";
        $html .= "<table class=\"data\"><thead><tr><th>{$t['date']}</th><th>{$t['customer']}</th>"
            . "<th class=\"num\">{$t['amount']}</th><th class=\"right\">{$t['status']}</th></tr></thead><tbody>";

        if (empty($d['recent_sales'])) {
            $html .= "<tr><td colspan=\"4\" class=\"muted\">{$t['noData']}</td></tr>";
        }
        foreach ($d['recent_sales'] as $s) {
            [$label, $class] = $this->paymentStatus((float) $s['total'], (float) $s['paid']);
            $html .= '<tr>'
                . '<td class="nowrap">' . $this->day($s['created_at']) . '</td>'
                . '<td>' . $this->e($s['customer_name']) . '</td>'
                . '<td class="num">' . $this->dt((float) $s['total']) . '</td>'
                . '<td class="right"><span class="pill ' . $class . '">' . $label . '</span></td>'
                . '</tr>';
        }
        $html .= '</tbody></table></div>';

        // ── Customers to follow up ──
        $html .= '<div class="section">';
        $html .= "<h2>{$t['followUpSection']}</h2>";
        $html .= "<table class=\"data\"><thead><tr><th>{$t['customer']}</th><th class=\"num\">{$t['balanceDue']}</th>"
            . "<th class=\"num\">{$t['score']}</th><th>{$t['reason']}</th></tr></thead><tbody>";

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
        $html .= '</tbody></table></div>';

        $html .= "<div class=\"footer\">{$t['footer']}</div></div></body></html>";

        return $html;
    }

    /// Sort key: out of stock first, then the shortest coverage, then the rest.
    private function criticality(array $s): int {
        if ((int) $s['current_stock'] <= 0) return -1_000_000;
        if (($s['urgency'] ?? 'ok') !== 'ok') {
            // Fewer days of cover ranks higher; no sales data ranks last of the flagged.
            return $s['days_of_coverage'] !== null ? (int) $s['days_of_coverage'] : 9_000;
        }
        return 1_000_000;
    }

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
        // Same format as the app: 4 005.00 DT (non-breaking spaces).
        return number_format($value, 2, '.', " ") . " DT";
    }

    private function day(?string $value): string {
        return $value ? date('d/m/Y', strtotime($value)) : '—';
    }

    private function e(?string $value): string {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    private function css(): string {
        // The app's design system: white, 1px #E4E7EC borders, #1D4ED8
        // accent, #101828 text, status text in the readable 700 shades.
        return <<<'CSS'
@page { margin: 0; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 11.5px; color: #101828; margin: 0; background: #FFFFFF; }

.band { background: #FFFFFF; padding: 26px 40px 20px 40px; border-bottom: 1px solid #E4E7EC; }
.band table { width: 100%; border-collapse: collapse; }
.band td { vertical-align: middle; padding: 0; }
.logo { width: 40px; height: 34px; line-height: 30px; padding-top: 6px; background: #1D4ED8; border-radius: 10px;
        color: #FFFFFF; text-align: center; font-size: 22px; font-weight: bold; }
.brand { font-size: 17px; font-weight: bold; color: #101828; }
.company { font-size: 15px; font-weight: bold; color: #101828; }
.band-sub { font-size: 10.5px; color: #667085; margin-top: 3px; }

.content { padding: 24px 40px 34px 40px; }

.kpis { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 0 -8px; }
.kpi { width: 25%; background: #FFFFFF; border: 1px solid #E4E7EC; border-radius: 10px;
       padding: 12px 14px; vertical-align: top; }
.kpi-label { font-size: 10px; color: #667085; }
.kpi-value { font-size: 16px; font-weight: bold; margin-top: 6px; color: #101828; white-space: nowrap; }

/* Keep a section with its heading; let the report run onto a second page. */
.section { page-break-inside: avoid; }
h2 { font-size: 13.5px; color: #101828; margin: 26px 0 8px 0; padding-left: 8px;
     border-left: 3px solid #1D4ED8; }
.note { font-size: 10.5px; color: #667085; margin: 0 0 8px 0; }

table.data { width: 100%; border-collapse: collapse; border: 1px solid #E4E7EC; }
table.data th { background: #F9FAFB; color: #667085; text-align: left; font-weight: normal;
                padding: 8px 10px; font-size: 10.5px; border-bottom: 1px solid #E4E7EC; }
table.data td { padding: 9px 10px; border-bottom: 1px solid #E4E7EC;
                vertical-align: middle; font-size: 11.5px; }
table.data tr { page-break-inside: avoid; }
.num { text-align: right; white-space: nowrap; }
.right { text-align: right; }
.nowrap { white-space: nowrap; }

.pill { padding: 2px 7px; border-radius: 4px; font-size: 10px; white-space: nowrap; }
.danger { color: #B91C1C; background: #FEF2F2; }
.warning { color: #B45309; background: #FFFBEB; }
.success { color: #15803D; background: #F0FDF4; }

.text-danger { color: #B91C1C; font-weight: bold; }
.muted { color: #667085; }
.footer { margin-top: 30px; padding-top: 12px; border-top: 1px solid #E4E7EC;
          font-size: 9.5px; color: #667085; text-align: center; }
CSS;
    }
}