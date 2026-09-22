<?php
namespace App\Repositories;

/// Builds the business context sent to the AI copilot.
///
/// Every figure comes from the same repository methods the app screens use
/// (Products, Customers, Suppliers, Sales, Purchases), fetched fresh on each
/// question. The assistant therefore sees exactly what the user sees — it
/// cannot quote a stock level or a balance the screens don't show.
class AiContextRepository {
    private const RECENT_LIMIT = 10;

    public function buildContext(string $tenantId, string $locale = 'en'): string {
        $fr = $locale === 'fr';

        $products  = (new ProductRepository())->findAllByTenant($tenantId);
        $customers = (new CustomerRepository())->findAllByTenant($tenantId);
        $suppliers = (new SupplierRepository())->findAllByTenant($tenantId);
        $sales     = array_slice((new SaleRepository())->findAllByTenant($tenantId), 0, self::RECENT_LIMIT);
        $purchases = array_slice((new PurchaseRepository())->findAllByTenant($tenantId), 0, self::RECENT_LIMIT);

        $out = [];
        $out[] = $this->instructions($fr);
        $out[] = '';
        $out[] = ($fr ? '=== DONNÉES EN DIRECT AU ' : '=== LIVE DATA AS OF ') . date('d/m/Y H:i') . ' ===';

        // ── Summary: pre-computed so the model never has to add up rows ──
        $receivables = 0.0;
        $owingCustomers = [];
        foreach ($customers as $c) {
            $balance = $this->num($c['total_purchases']) - $this->num($c['total_paid']);
            if ($balance > 0.009) {
                $receivables += $balance;
                $owingCustomers[] = $c['name'] . ' (' . $this->dt($balance) . ')';
            }
        }

        $payables = 0.0;
        foreach ($suppliers as $s) {
            $owed = $this->num($s['total_purchases']) - $this->num($s['total_paid']);
            if ($owed > 0.009) $payables += $owed;
        }

        $stockValue = 0.0;
        $outOfStock = [];
        $lowStock = [];
        foreach ($products as $p) {
            $stock = (int) $p['current_stock'];
            if ($stock > 0) $stockValue += $stock * $this->num($p['cost']);
            if (!(int) $p['is_active']) continue;
            if ($stock <= 0) {
                $outOfStock[] = $p['name'];
            } elseif ($stock <= (int) $p['min_threshold']) {
                $lowStock[] = $p['name'];
            }
        }

        $none = $fr ? 'aucun' : 'none';
        $out[] = '';
        $out[] = $fr ? 'SYNTHÈSE :' : 'SUMMARY:';
        $out[] = ($fr ? '- Créances clients totales : ' : '- Total customer receivables: ') . $this->dt($receivables);
        $out[] = ($fr ? '- Clients qui doivent de l\'argent : ' : '- Customers who owe money: ')
            . ($owingCustomers ? implode(', ', $owingCustomers) : $none);
        $out[] = ($fr ? '- Montant dû aux fournisseurs : ' : '- Amount owed to suppliers: ') . $this->dt($payables);
        $out[] = ($fr ? '- Valeur du stock au coût : ' : '- Stock value at cost: ') . $this->dt($stockValue);
        $out[] = ($fr ? '- Produits en rupture : ' : '- Products out of stock: ')
            . ($outOfStock ? implode(', ', $outOfStock) : $none);
        $out[] = ($fr ? '- Produits en stock bas : ' : '- Products with low stock: ')
            . ($lowStock ? implode(', ', $lowStock) : $none);

        // ── Products ──
        $out[] = '';
        $out[] = $fr ? 'PRODUITS :' : 'PRODUCTS:';
        if (!$products) $out[] = '- ' . $none;
        foreach ($products as $p) {
            $stock = (int) $p['current_stock'];
            $out[] = sprintf(
                '- %s | %s %d %s | %s %d | %s %s | %s %s%% | %s',
                $p['name'],
                $fr ? 'stock' : 'stock', $stock, $p['unit'] ?? '',
                $fr ? 'seuil min' : 'min threshold', (int) $p['min_threshold'],
                $fr ? 'prix HT' : 'price excl. VAT', $this->dt($this->num($p['price'])),
                $fr ? 'TVA' : 'VAT', rtrim(rtrim(number_format($this->num($p['vat_rate']), 2, '.', ''), '0'), '.'),
                $this->productStatus($p, $fr)
            );
        }

        // ── Customers ──
        $out[] = '';
        $out[] = $fr
            ? 'CLIENTS (reste dû = achats − paiements) :'
            : 'CUSTOMERS (outstanding = purchases − payments):';
        if (!$customers) $out[] = '- ' . $none;
        foreach ($customers as $c) {
            $total = $this->num($c['total_purchases']);
            $paid = $this->num($c['total_paid']);
            $balance = $total - $paid;
            $limit = $this->num($c['credit_limit']);

            $line = sprintf(
                '- %s | %s %s | %s %s | %s %s | %s %s | %s %s | %d %s | %s %s',
                $c['name'],
                $fr ? 'tél' : 'phone', $c['phone'] ?: '—',
                $fr ? 'achats' : 'purchases', $this->dt($total),
                $fr ? 'payé' : 'paid', $this->dt($paid),
                $fr ? 'reste dû' : 'outstanding', $this->dt(max($balance, 0)),
                $fr ? 'plafond' : 'credit limit', $this->dt($limit),
                (int) $c['order_count'], $fr ? 'commandes' : 'orders',
                $fr ? 'dernier achat' : 'last purchase', $this->day($c['last_purchase'] ?? null)
            );
            if ($limit > 0 && $balance > $limit) {
                $line .= $fr ? ' | PLAFOND DÉPASSÉ' : ' | OVER CREDIT LIMIT';
            }
            $out[] = $line;
        }

        // ── Suppliers ──
        $out[] = '';
        $out[] = $fr ? 'FOURNISSEURS :' : 'SUPPLIERS:';
        if (!$suppliers) $out[] = '- ' . $none;
        foreach ($suppliers as $s) {
            $total = $this->num($s['total_purchases']);
            $paid = $this->num($s['total_paid']);
            $out[] = sprintf(
                '- %s | %s %s | %s %d %s | %s %s | %s %s | %s %s',
                $s['name'],
                $fr ? 'tél' : 'phone', $s['phone'] ?: '—',
                $fr ? 'délai' : 'lead time', (int) $s['lead_time_days'], $fr ? 'jours' : 'days',
                $fr ? 'achats' : 'purchases', $this->dt($total),
                $fr ? 'payé' : 'paid', $this->dt($paid),
                $fr ? 'nous devons' : 'we owe', $this->dt(max($total - $paid, 0))
            );
        }

        // ── Recent sales ──
        $out[] = '';
        $out[] = $fr
            ? 'VENTES RÉCENTES (' . self::RECENT_LIMIT . ' dernières) :'
            : 'RECENT SALES (last ' . self::RECENT_LIMIT . '):';
        if (!$sales) $out[] = '- ' . $none;
        foreach ($sales as $s) {
            $total = $this->num($s['total']);
            $paid = $this->num($s['paid']);
            $out[] = sprintf(
                '- %s | %s | %s %s | %s %s | %s %s | %s',
                $this->day($s['created_at']),
                $s['customer_name'],
                $fr ? 'total' : 'total', $this->dt($total),
                $fr ? 'payé' : 'paid', $this->dt($paid),
                $fr ? 'reste' : 'remaining', $this->dt(max($total - $paid, 0)),
                $this->paymentStatus($total, $paid, $fr)
            );
        }

        // ── Recent purchases ──
        $out[] = '';
        $out[] = $fr
            ? 'ACHATS RÉCENTS (' . self::RECENT_LIMIT . ' derniers) :'
            : 'RECENT PURCHASES (last ' . self::RECENT_LIMIT . '):';
        if (!$purchases) $out[] = '- ' . $none;
        foreach ($purchases as $p) {
            $total = $this->num($p['total']);
            $paid = $this->num($p['paid']);
            $received = ($p['status'] ?? '') === 'received';
            $out[] = sprintf(
                '- %s | %s | %s %s | %s | %s %s | %s',
                $this->day($p['created_at']),
                $p['supplier_name'],
                $fr ? 'total' : 'total', $this->dt($total),
                $received ? ($fr ? 'REÇU' : 'RECEIVED') : ($fr ? 'EN ATTENTE DE LIVRAISON' : 'PENDING DELIVERY'),
                $fr ? 'payé' : 'paid', $this->dt($paid),
                $this->paymentStatus($total, $paid, $fr)
            );
        }

        return implode("\n", $out);
    }

    private function instructions(bool $fr): string {
        if ($fr) {
            return implode("\n", [
                "Tu es l'assistant métier d'une PME qui utilise Forever One.",
                'Règles :',
                "1. Réponds UNIQUEMENT en français, quelle que soit la langue de la question. Chaque mot de ta réponse doit être en français.",
                "2. Utilise UNIQUEMENT les données en direct ci-dessous. Elles viennent d'être lues dans la base, elles sont donc à jour. Si une information n'y figure pas, dis que tu ne l'as pas — n'invente jamais.",
                '3. Écris les montants avec deux décimales suivies de DT (ex. 53.55 DT) et les dates au format JJ/MM/AAAA.',
                '4. Mets en forme en Markdown : paragraphes courts, listes à puces, **gras** pour les chiffres et noms importants.',
                "5. N'écris JAMAIS de balises entre crochets comme [ALERTE RUPTURE] ou [ALERTE]. Pour signaler un produit, écris son statut en toutes lettres, ex. **Rupture de stock**.",
                '6. Sois concis et concret.',
            ]);
        }
        return implode("\n", [
            'You are the business assistant of an SME that uses Forever One.',
            'Rules:',
            '1. Answer ONLY in English, whatever language the question is written in. Every word of your answer must be in English.',
            "2. Use ONLY the live data below. It was read from the database a moment ago, so it is current. If something is not in the data, say you don't have that information — never guess.",
            '3. Write amounts with two decimals followed by DT (e.g. 53.55 DT) and dates as DD/MM/YYYY.',
            '4. Format with Markdown: short paragraphs, bullet lists, **bold** for key figures and names.',
            '5. NEVER output square-bracket tags such as [STOCK ALERT] or [ALERT]. To flag a product, write its status in words, e.g. **Out of stock**.',
            '6. Be concise and concrete.',
        ]);
    }

    /// Same rule as the Products screen: 0 or less → out, at or below the
    /// minimum → low, otherwise fine.
    private function productStatus(array $p, bool $fr): string {
        if (!(int) $p['is_active']) return $fr ? 'INACTIF' : 'INACTIVE';
        $stock = (int) $p['current_stock'];
        if ($stock <= 0) return $fr ? 'RUPTURE DE STOCK' : 'OUT OF STOCK';
        if ($stock <= (int) $p['min_threshold']) return $fr ? 'STOCK BAS' : 'LOW STOCK';
        return 'OK';
    }

    private function paymentStatus(float $total, float $paid, bool $fr): string {
        if ($total - $paid <= 0.009) return $fr ? 'PAYÉ' : 'PAID';
        if ($paid > 0) return $fr ? 'PARTIELLEMENT PAYÉ' : 'PARTIALLY PAID';
        return $fr ? 'IMPAYÉ' : 'UNPAID';
    }

    private function num($value): float {
        return (float) ($value ?? 0);
    }

    private function dt(float $value): string {
        return number_format($value, 2, '.', '') . ' DT';
    }

    private function day(?string $value): string {
        return $value ? date('d/m/Y', strtotime($value)) : '—';
    }
}