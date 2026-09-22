<?php
namespace App\Services;

use App\Config\Database;
use App\Repositories\CustomerRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SupplierRepository;

/// Answers a few common questions straight from the database when the AI
/// service is unavailable.
///
/// Deliberately narrow: three intents it can answer exactly, and null for
/// everything else. A confident wrong answer would be worse than telling the
/// user the assistant is unavailable.
class LocalAnswerService {
    private const INTENT_DEBTS = 'debts';
    private const INTENT_STOCK = 'stock';
    private const INTENT_ACTIVITY = 'activity';

    /// Accent-free, lowercase keywords — the question is normalised the same way.
    private const KEYWORDS = [
        self::INTENT_DEBTS => [
            'owe', 'owes', 'owing', 'debt', 'debts', 'unpaid', 'receivable',
            'receivables', 'outstanding',
            'doit', 'doivent', 'dette', 'dettes', 'impaye', 'impayes', 'impayee',
            'impayees', 'creance', 'creances', 'solde', 'soldes',
        ],
        self::INTENT_STOCK => [
            'stock', 'stocks', 'restock', 'reorder', 'shortage', 'threshold', 'low',
            'rupture', 'ruptures', 'seuil', 'seuils', 'reappro', 'manque',
            'epuise', 'epuises', 'commander',
        ],
        self::INTENT_ACTIVITY => [
            'sale', 'sales', 'revenue', 'turnover', 'sold', 'month', 'monthly',
            // The wording of the suggestion chips, which is what most users tap.
            'summary', 'activity', 'business', 'performance', 'overview',
            'vente', 'ventes', 'chiffre', 'affaires', 'mois', 'vendu', 'ca',
            'resume', 'activite', 'bilan', 'apercu', 'synthese',
        ],
    ];

    /// Returns a ready-to-display markdown answer, or null when the question
    /// isn't one of the three this service can answer honestly.
    public function answer(string $tenantId, string $question, string $locale = 'en'): ?string {
        $intent = $this->detect($question);
        if ($intent === null) return null;

        $fr = $locale === 'fr';

        $body = match ($intent) {
            self::INTENT_DEBTS => $this->debts($tenantId, $fr),
            self::INTENT_STOCK => $this->stock($tenantId, $fr),
            self::INTENT_ACTIVITY => $this->activity($tenantId, $fr),
        };

        // The user is told where the figures come from — never passed off as AI.
        $note = $fr
            ? "_Réponse calculée directement depuis vos données : l'assistant IA est momentanément indisponible._"
            : '_Answered directly from your data — the AI assistant is temporarily unavailable._';

        return $body . "\n\n" . $note;
    }

    private function detect(string $question): ?string {
        $text = $this->normalize($question);

        $best = null;
        $bestScore = 0;
        foreach (self::KEYWORDS as $intent => $words) {
            $score = 0;
            foreach ($words as $word) {
                if (str_contains($text, ' ' . $word . ' ')) $score++;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $intent;
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    /// Lowercase, accents stripped, punctuation turned into spaces and padded,
    /// so "Qui me doit de l'argent ?" matches the keyword "doit".
    private function normalize(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u',
            'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        return ' ' . trim($text) . ' ';
    }

    // ───────────────────────── Intents ─────────────────────────

    private function debts(string $tenantId, bool $fr): string {
        $customers = (new CustomerRepository())->findAllByTenant($tenantId);

        $owing = [];
        $total = 0.0;
        foreach ($customers as $c) {
            $balance = (float) $c['total_purchases'] - (float) $c['total_paid'];
            if ($balance <= 0.009) continue;

            $limit = (float) $c['credit_limit'];
            $owing[] = [
                'name' => $c['name'],
                'balance' => $balance,
                'over_limit' => $limit > 0 && $balance > $limit,
            ];
            $total += $balance;
        }

        if (!$owing) {
            return $fr
                ? "**Aucun client** ne vous doit d'argent actuellement."
                : '**No customer** owes you money right now.';
        }

        usort($owing, fn($a, $b) => $b['balance'] <=> $a['balance']);
        $count = count($owing);

        $lines = [];
        $lines[] = $fr
            ? ($count === 1
                ? "**1 client** vous doit **{$this->dt($total)}**."
                : "**$count clients** vous doivent au total **{$this->dt($total)}**.")
            : ($count === 1
                ? "**1 customer** owes you **{$this->dt($total)}**."
                : "**$count customers** owe you a total of **{$this->dt($total)}**.");
        $lines[] = '';

        foreach ($owing as $o) {
            $line = '- **' . $o['name'] . '** — ' . $this->dt($o['balance']);
            if ($o['over_limit']) {
                $line .= $fr ? ' · plafond de crédit dépassé' : ' · over credit limit';
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function stock(string $tenantId, bool $fr): string {
        $products = (new ProductRepository())->findAllByTenant($tenantId);

        $out = [];
        $low = [];
        foreach ($products as $p) {
            if (!(int) $p['is_active']) continue;

            $stock = (int) $p['current_stock'];
            $min = (int) $p['min_threshold'];
            $entry = [
                'name' => $p['name'],
                'stock' => $stock,
                'unit' => $p['unit'] ?? '',
                'min' => $min,
            ];

            if ($stock <= 0) {
                $out[] = $entry;
            } elseif ($stock <= $min) {
                $low[] = $entry;
            }
        }

        if (!$out && !$low) {
            return $fr
                ? "**Aucun produit** n'est en rupture ni sous son seuil."
                : '**No product** is out of stock or below its threshold.';
        }

        $lines = [];

        if ($out) {
            $lines[] = $fr
                ? '**Rupture de stock (' . count($out) . ')**'
                : '**Out of stock (' . count($out) . ')**';
            foreach ($out as $p) {
                $lines[] = '- **' . $p['name'] . '** — 0 ' . $p['unit']
                    . ($fr ? ' · seuil ' : ' · threshold ') . $p['min'];
            }
            $lines[] = '';
        }

        if ($low) {
            $lines[] = $fr
                ? '**Stock bas (' . count($low) . ')**'
                : '**Low stock (' . count($low) . ')**';
            foreach ($low as $p) {
                $lines[] = '- **' . $p['name'] . '** — ' . $p['stock'] . ' ' . $p['unit']
                    . ($fr ? ' · seuil ' : ' · threshold ') . $p['min'];
            }
        }

        return trim(implode("\n", $lines));
    }

    /// Sales for the month, then the cash position and the stock — the same
    /// six figures the dashboard shows.
    private function activity(string $tenantId, bool $fr): string {
        $pdo = Database::connect();

        // ── This month against last month ──
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total), 0) AS total, COUNT(*) AS count
             FROM sales
             WHERE tenant_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        );
        $stmt->execute([$tenantId]);
        $current = $stmt->fetch(\PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total), 0) AS total, COUNT(*) AS count
             FROM sales
             WHERE tenant_id = ?
               AND created_at >= DATE_FORMAT(NOW() - INTERVAL 1 MONTH, '%Y-%m-01')
               AND created_at <  DATE_FORMAT(NOW(), '%Y-%m-01')"
        );
        $stmt->execute([$tenantId]);
        $previous = $stmt->fetch(\PDO::FETCH_ASSOC);

        $currentTotal = (float) $current['total'];
        $currentCount = (int) $current['count'];
        $previousTotal = (float) $previous['total'];
        $previousCount = (int) $previous['count'];

        // ── Money owed, both ways ──
        $receivables = 0.0;
        $owingCustomers = 0;
        foreach ((new CustomerRepository())->findAllByTenant($tenantId) as $c) {
            $balance = (float) $c['total_purchases'] - (float) $c['total_paid'];
            if ($balance > 0.009) {
                $receivables += $balance;
                $owingCustomers++;
            }
        }

        $payables = 0.0;
        foreach ((new SupplierRepository())->findAllByTenant($tenantId) as $s) {
            $owed = (float) $s['total_purchases'] - (float) $s['total_paid'];
            if ($owed > 0.009) $payables += $owed;
        }

        // ── Stock ──
        $stockValue = 0.0;
        $outOfStock = 0;
        $lowStock = 0;
        foreach ((new ProductRepository())->findAllByTenant($tenantId) as $p) {
            $stock = (int) $p['current_stock'];
            if ($stock > 0) $stockValue += $stock * (float) $p['cost'];

            if (!(int) $p['is_active']) continue;
            if ($stock <= 0) {
                $outOfStock++;
            } elseif ($stock <= (int) $p['min_threshold']) {
                $lowStock++;
            }
        }

        $lines = [];

        $lines[] = $fr
            ? "Ce mois-ci : **{$this->dt($currentTotal)}** sur **$currentCount ventes**."
            : "This month: **{$this->dt($currentTotal)}** across **$currentCount sales**.";

        if ($previousTotal > 0.009) {
            $change = ($currentTotal - $previousTotal) / $previousTotal * 100;
            $sign = $change >= 0 ? '+' : '−';
            $value = number_format(abs($change), 1, '.', '');

            $lines[] = $fr
                ? "Mois dernier : **{$this->dt($previousTotal)}** sur **$previousCount ventes** — soit **$sign$value %** ce mois-ci."
                : "Last month: **{$this->dt($previousTotal)}** across **$previousCount sales** — that is **$sign$value %** this month.";
        } else {
            $lines[] = $fr
                ? 'Aucune vente le mois dernier, pas de comparaison possible.'
                : 'No sales last month, so there is nothing to compare with.';
        }

        $lines[] = '';
        $lines[] = $fr ? '**Trésorerie**' : '**Cash position**';
        $lines[] = $fr
            ? "- Créances : **{$this->dt($receivables)}** réparties sur **"
                . $this->plural($owingCustomers, 'client', 'clients', $fr) . '**'
            : "- Receivables: **{$this->dt($receivables)}** from **"
                . $this->plural($owingCustomers, 'customer', 'customers', $fr) . '**';
        $lines[] = $fr
            ? "- Dettes fournisseurs : **{$this->dt($payables)}**"
            : "- Payables: **{$this->dt($payables)}**";

        $lines[] = '';
        $lines[] = '**Stock**';
        $lines[] = $fr
            ? "- Valeur du stock : **{$this->dt($stockValue)}**"
            : "- Stock value: **{$this->dt($stockValue)}**";
        $lines[] = $fr
            ? '- En rupture : **' . $this->plural($outOfStock, 'produit', 'produits', $fr) . '**'
            : '- Out of stock: **' . $this->plural($outOfStock, 'product', 'products', $fr) . '**';
        $lines[] = $fr
            ? '- Sous le seuil : **' . $this->plural($lowStock, 'produit', 'produits', $fr) . '**'
            : '- Below threshold: **' . $this->plural($lowStock, 'product', 'products', $fr) . '**';

        return implode("\n", $lines);
    }

    /// French treats zero as singular ("0 produit"), English does not.
    private function plural(int $count, string $one, string $many, bool $fr): string {
        $singular = $fr ? $count <= 1 : $count === 1;
        return $count . ' ' . ($singular ? $one : $many);
    }

    private function dt(float $value): string {
        return number_format($value, 2, '.', '') . ' DT';
    }
}