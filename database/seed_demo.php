<?php
/**
 * Demo data for Épicerie El Baraka (Sfax), dated RELATIVE TO TODAY.
 *
 * Run before every demo:   php database/seed_demo.php
 *
 * - Resets the demo tenant ONLY. Other companies are not touched.
 * - Keeps the demo company's users, so logins still work
 *   (created on first run: admin@elbaraka.tn / stock@ / ventes@, password baraka2026).
 * - Regenerates ~50 sales over the last 60 days (busier recently, some today),
 *   purchases, payments and stock movements that agree with each other.
 * - A realistic mix: most invoices paid, some partially paid, a few overdue;
 *   2-3 low-stock products, 1 out of stock, 3 dormant (no sale in 30+ days);
 *   supplier deliveries mostly on time, some late, one pending and late.
 *
 * Deterministic: the same "shape" every run (fixed random seed), shifted to
 * today's date, so a demo always shows the same story.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Core\Uuid;

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
mt_srand(20261111);

const DEMO_TENANT = 'Épicerie El Baraka';
const DEMO_ADMIN = 'admin@elbaraka.tn';
const DEMO_PASSWORD = 'baraka2026';

function uuid(): string { return Uuid::generate(); }
function money(float $v): float { return round($v, 2); }
/** A datetime N days ago (fractional days allowed), at a business hour. */
function at(int $daysAgo, string $hm): string {
    return date('Y-m-d ' . $hm . ':00', strtotime("-$daysAgo days"));
}
function plusDays(string $datetime, int $days): string {
    return date('Y-m-d H:i:s', strtotime($datetime . " +$days days"));
}
function dateOnly(string $datetime): string { return substr($datetime, 0, 10); }
function rnd(int $min, int $max): int { return mt_rand($min, $max); }
function hourBetween(int $from, int $to): string {
    return sprintf('%02d:%02d', rnd($from, $to), rnd(0, 11) * 5);
}

echo "Forever One demo seed: " . DEMO_TENANT . "\n" . str_repeat('=', 56) . "\n";

// ─────────────────────── 1. Find or create the demo tenant ───────────────────────
$tenantId = $pdo->query(
    "SELECT tenant_id FROM users WHERE email = " . $pdo->quote(DEMO_ADMIN) . " LIMIT 1"
)->fetchColumn();

if (!$tenantId) {
    $tenantId = uuid();
    $pdo->prepare('INSERT INTO tenants (id, name) VALUES (?, ?)')->execute([$tenantId, DEMO_TENANT]);
    $hash = password_hash(DEMO_PASSWORD, PASSWORD_BCRYPT);
    $u = $pdo->prepare(
        'INSERT INTO users (id, tenant_id, email, password_hash, full_name, phone, role, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $u->execute([uuid(), $tenantId, DEMO_ADMIN, $hash, 'Mohamed Trabelsi', '74 401 220', 'admin']);
    $u->execute([uuid(), $tenantId, 'stock@elbaraka.tn', $hash, 'Sonia Ben Amor', '98 214 663', 'stock']);
    $u->execute([uuid(), $tenantId, 'ventes@elbaraka.tn', $hash, 'Karim Jlassi', '22 760 148', 'commercial']);
    echo "Demo tenant and 3 users created (password " . DEMO_PASSWORD . ")\n";
} else {
    $pdo->prepare('UPDATE tenants SET name = ? WHERE id = ?')->execute([DEMO_TENANT, $tenantId]);
    echo "Demo tenant found; its users and passwords are kept\n";
}

$adminId = $pdo->query(
    "SELECT id FROM users WHERE email = " . $pdo->quote(DEMO_ADMIN)
)->fetchColumn();

// ─────────────────────── 2. Reset this tenant's data only ───────────────────────
$pdo->beginTransaction();
$t = $pdo->quote($tenantId);
$pdo->exec("DELETE sl FROM sale_lines sl JOIN sales s ON s.id = sl.sale_id WHERE s.tenant_id = $t");
$pdo->exec("DELETE pl FROM purchase_lines pl JOIN purchases p ON p.id = pl.purchase_id WHERE p.tenant_id = $t");
foreach (['payments', 'stock_movements', 'sales', 'purchases', 'products', 'categories',
          'customers', 'suppliers', 'warehouses', 'documents', 'ai_conversations', 'audit_logs'] as $table) {
    $pdo->exec("DELETE FROM `$table` WHERE tenant_id = $t");
}
echo "Previous demo data removed (other companies untouched)\n";

// ───────────────────────── 3. Reference data ─────────────────────────
$categoryData = [
    'boissons'  => ['Boissons', 'Cafés, thés, eaux et jus', '#1D4ED8'],
    'epicerie'  => ['Épicerie sèche', 'Huiles, semoules, pâtes et conserves', '#D97706'],
    'frais'     => ['Produits frais', 'Lait, yaourts et dérivés', '#16A34A'],
    'hygiene'   => ['Hygiène', 'Savons et papiers', '#667085'],
    'emballage' => ['Emballage', 'Sacs, barquettes et cartons', '#101828'],
];
$categories = [];
$stmt = $pdo->prepare('INSERT INTO categories (id, tenant_id, name, description, color) VALUES (?, ?, ?, ?, ?)');
foreach ($categoryData as $key => [$name, $description, $color]) {
    $categories[$key] = uuid();
    $stmt->execute([$categories[$key], $tenantId, $name, $description, $color]);
}

$warehouses = ['principal' => uuid(), 'sakiet' => uuid()];
$stmt = $pdo->prepare(
    'INSERT INTO warehouses (id, tenant_id, code, name, location, address, manager_name, phone, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
);
$stmt->execute([$warehouses['principal'], $tenantId, 'DEP-01', 'Dépôt Principal', 'Sfax',
    'Route de Gabès km 2, Sfax', 'Sonia Ben Amor', '74 401 221']);
$stmt->execute([$warehouses['sakiet'], $tenantId, 'DEP-02', 'Dépôt Sakiet Ezzit', 'Sakiet Ezzit',
    'Zone industrielle Sakiet Ezzit, Sfax', 'Karim Jlassi', '74 401 222']);

// key => [name, contact, phone, email, address, tax id, payment terms, lead time, iban]
$supplierData = [
    'cafe'    => ['Société Tunisienne de Café', 'Hichem Gharbi', '71 234 510', 'contact@stcafe.tn',
                  'Rue de Carthage, Tunis', '1122334/A/M/000', 14, 4, 'TN59 1000 6035 0000 1234 5678'],
    'danone'  => ['Délice Danone Distribution', 'Fatma Khelifi', '71 880 400', 'commandes@delice.tn',
                  'Avenue Habib Bourguiba, Sousse', '2233445/B/M/000', 30, 2, 'TN59 0400 3021 0000 9876 5432'],
    'sotupro' => ['SOTUPRODIS Sfax', 'Anis Mahjoub', '74 612 330', 'anis.mahjoub@sotuprodis.tn',
                  'Zone industrielle Poudrière, Sfax', '3344556/C/M/000', 21, 3, 'TN59 0800 1044 0000 4455 6677'],
    'emball'  => ['Emballages du Sud', 'Nizar Bouzid', '74 295 118', 'nizar@embsud.tn',
                  'Route de Tunis km 5, Sfax', '4455667/D/M/000', 30, 5, 'TN59 1200 7055 0000 3344 5566'],
];
$suppliers = [];
$stmt = $pdo->prepare(
    'INSERT INTO suppliers (id, tenant_id, name, contact_person, phone, email, address, tax_id,
                            payment_terms_days, lead_time_days, bank_account)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ($supplierData as $key => $s) {
    $suppliers[$key] = uuid();
    $stmt->execute(array_merge([$suppliers[$key], $tenantId], $s));
}

// key => [name, type, phone, email, address, tax id, credit limit, payment terms, weight]
$customerData = [
    'majestic' => ['Café Majestic', 'company', '74 225 880', 'majestic.sfax@gmail.com',
                   'Avenue Hédi Chaker, Sfax', '5566778/E/M/000', 6000, 30, 5],
    'golfe'    => ['Restaurant Le Golfe', 'company', '74 448 190', 'contact@legolfe.tn',
                   'Corniche, Sfax', '6677889/F/M/000', 3000, 15, 3],
    'ennour'   => ['Supérette Ennour', 'company', '74 371 600', 'superette.ennour@gmail.com',
                   'Cité El Habib, Sfax', '7788990/G/M/000', 9000, 30, 5],
    'oliviers' => ['Hôtel Les Oliviers', 'company', '74 225 000', 'achats@lesoliviers.tn',
                   'Avenue Habib Thameur, Sfax', '8899001/H/M/000', 12000, 45, 4],
    'mongi'    => ['Mongi Sassi', 'individual', '98 443 217', null,
                   'Rue Mongi Slim, Sfax', null, 0, 0, 3],
    'ali'      => ['Snack Chez Ali', 'company', '25 118 740', null,
                   'Route de l\'Aéroport, Sfax', '9900112/I/M/000', 900, 15, 3],
    'bahja'    => ['Restaurant El Bahja', 'company', '74 210 455', 'elbahja.sfax@gmail.com',
                   'Rue Habib Maazoun, Sfax', '1029384/J/M/000', 2500, 15, 2],
];
$customers = [];
$customerPool = [];
$stmt = $pdo->prepare(
    'INSERT INTO customers (id, tenant_id, name, customer_type, phone, email, address, tax_id,
                            credit_limit, payment_terms_days)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ($customerData as $key => $c) {
    $customers[$key] = uuid();
    $stmt->execute(array_merge([$customers[$key], $tenantId], array_slice($c, 0, 8)));
    for ($i = 0; $i < $c[8]; $i++) $customerPool[] = $key;
}

// key => [name, category, supplier, cost, price HT, VAT, unit, purchase unit, units/purchase,
//         min threshold, final stock target, role]
// role: 'active' sells normally · 'low' ends below its minimum · 'out' ends at 0
//       'dormant' had sales, none in the last 30 days
$productData = [
    'cafe'    => ['Café moulu Bondin 500 g',           'boissons',  'cafe',    8.20, 12.50, 13, 'paquet', 'carton', 12, 20,  48, 'active'],
    'the'     => ['Thé vert Sultan 250 g',             'boissons',  'cafe',    4.10,  6.90, 13, 'boîte',  'carton', 24, 25,  60, 'active'],
    'eau'     => ['Eau minérale Safia 1.5 L (pack 6)', 'boissons',  'sotupro', 3.60,  5.40, 19, 'pack',   'palette',60, 30,  12, 'low'],
    'jus'     => ['Jus d\'orange Délice 1 L',          'boissons',  'danone',  2.40,  3.50, 19, 'brique', 'carton', 12, 15,  44, 'active'],
    'huile'   => ['Huile d\'olive extra vierge 5 L',   'epicerie',  'sotupro',62.00, 89.00, 19, 'bidon',  'carton',  4, 10,  22, 'active'],
    'semoule' => ['Semoule fine 5 kg',                 'epicerie',  'sotupro', 9.50, 13.80,  7, 'sac',    'palette',20, 15,   0, 'out'],
    'sucre'   => ['Sucre blanc 1 kg',                  'epicerie',  'sotupro', 1.90,  2.60,  7, 'paquet', 'sac',    25, 50, 140, 'active'],
    'pates'   => ['Pâtes spaghetti 500 g',             'epicerie',  'sotupro', 1.25,  1.90,  7, 'paquet', 'carton', 20, 60, 190, 'active'],
    'tomate'  => ['Concentré de tomate 800 g',         'epicerie',  'sotupro', 3.40,  4.90, 19, 'boîte',  'carton', 12, 20,   7, 'low'],
    'harissa' => ['Harissa du Cap Bon 380 g',          'epicerie',  'sotupro', 2.10,  3.20, 19, 'boîte',  'carton', 24, 15,  38, 'active'],
    'thon'    => ['Thon à l\'huile d\'olive 160 g',    'epicerie',  'sotupro', 3.90,  5.60, 19, 'boîte',  'carton', 48, 10,  36, 'dormant'],
    'lait'    => ['Lait demi-écrémé 1 L',              'frais',     'danone',  1.35,  1.75,  7, 'brique', 'pack',    6, 40,  96, 'active'],
    'yaourt'  => ['Yaourt nature (pack 8)',            'frais',     'danone',  3.80,  5.20,  7, 'pack',   'carton',  6, 15,  11, 'low'],
    'savon'   => ['Savon liquide 5 L',                 'hygiene',   'emball', 14.50, 21.00, 19, 'bidon',  'carton',  4,  8,  16, 'active'],
    'essuie'  => ['Papier essuie-tout (pack 6)',       'hygiene',   'emball',  6.70,  9.90, 19, 'pack',   'carton',  8, 12,  30, 'dormant'],
    'sacs'    => ['Sacs kraft 1 kg (x100)',            'emballage', 'emball', 11.00, 16.50, 19, 'carton', 'palette',30, 15,  41, 'active'],
    'barq'    => ['Barquettes aluminium (x50)',        'emballage', 'emball',  8.90, 13.20, 19, 'carton', 'palette',40, 10,  26, 'dormant'],
];
$sellable = array_keys(array_filter($productData, fn($p) => $p[11] !== 'dormant'));
$dormant = array_keys(array_filter($productData, fn($p) => $p[11] === 'dormant'));

// Typical quantity per sale line, by unit size.
function lineQty(string $key): int {
    return match ($key) {
        'huile', 'savon' => rnd(1, 4),
        'sacs', 'barq', 'essuie', 'yaourt' => rnd(2, 8),
        'cafe', 'the', 'eau', 'jus', 'harissa', 'tomate', 'thon', 'semoule' => rnd(3, 12),
        default => rnd(8, 30), // sucre, pates, lait
    };
}

// ───────────────────────── 4. The sales plan ─────────────────────────
// About one sale per business day over the last 60 days, spread evenly with
// normal day-to-day variation (quieter Sundays, busier Saturdays) and a mild
// upward trend. Today only gets the sales its elapsed opening hours allow,
// so today's bar is never out of proportion.
function saleTotal(array $lines, array $productData): float {
    $t = 0.0;
    foreach ($lines as [$key, $qty]) {
        $t += $qty * $productData[$key][4] * (1 + $productData[$key][5] / 100);
    }
    return $t;
}

$salesPlan = [];
$nowHour = (int) date('G');
for ($daysAgo = 60; $daysAgo >= 0; $daysAgo--) {
    $weekday = (int) date('w', strtotime("-$daysAgo days")); // 0 = Sunday
    $roll = rnd(1, 100);
    $count = $roll <= 88 ? 1 : 2;                            // every open day has sales
    if ($weekday === 0) $count = 0;                           // Sunday: closed
    if ($weekday === 6 && $count < 2 && rnd(1, 100) <= 40) $count++; // Saturday: busier
    if ($daysAgo === 0) {
        // Opening hours 8:00-19:00: only the share of the day already gone.
        $elapsed = max(0, min(11, $nowHour - 8)) / 11;
        $count = (int) round(1.2 * $elapsed);
    }
    for ($k = 0; $k < $count; $k++) {
        $lastHour = $daysAgo === 0 ? max(8, min(18, $nowHour - 1)) : 18;
        $lines = [];
        $pool = $sellable;
        shuffle($pool);
        foreach (array_slice($pool, 0, rnd(1, 3)) as $key) {
            $lines[] = [$key, lineQty($key)];
        }
        // A corner shop's ticket: trim quantities so one sale stays under
        // ~200 DT (today: in proportion to the part of the day already gone).
        $cap = $daysAgo === 0 ? max(60, 200 * max(0, min(11, $nowHour - 8)) / 11) : 200;
        while (saleTotal($lines, $productData) > $cap) {
            $i = array_search(max(array_column($lines, 1)), array_column($lines, 1));
            if ($lines[$i][1] <= 1) break;
            $lines[$i][1] = max(1, min($lines[$i][1] - 1, (int) floor($lines[$i][1] * 0.7)));
        }
        $salesPlan[] = [
            'days' => $daysAgo,
            'hour' => hourBetween(8, $lastHour),
            'customer' => $customerPool[array_rand($customerPool)],
            'lines' => $lines,
        ];
    }
}

// Dormant products: sold once or twice 35-58 days ago, never since.
foreach ($dormant as $key) {
    for ($k = 0; $k < rnd(1, 2); $k++) {
        $salesPlan[] = ['days' => rnd(35, 58), 'hour' => hourBetween(8, 18),
            'customer' => $customerPool[array_rand($customerPool)], 'lines' => [[$key, lineQty($key)]]];
    }
}
// The out-of-stock product sold its last units recently.
$salesPlan[] = ['days' => 2, 'hour' => '16:40', 'customer' => 'ennour', 'lines' => [['semoule', 4]]];
usort($salesPlan, fn($a, $b) => [$b['days'], $b['hour']] <=> [$a['days'], $a['hour']]);

// Tune the trend so "this month vs last month" (same day range, as the
// dashboard computes it) lands between +8% and +20%: scale the quantities
// of this month's sales until the ratio fits.
$thisStart = strtotime(date('Y-m-01 00:00:00'));
$prevStart = strtotime(date('Y-m-01 00:00:00', strtotime('first day of last month')));
$prevEnd = strtotime('-1 month');
$growth = function () use (&$salesPlan, $productData, $thisStart, $prevStart, $prevEnd): ?float {
    $cur = $prev = 0.0;
    foreach ($salesPlan as $s) {
        $ts = strtotime(at($s['days'], $s['hour']));
        $total = saleTotal($s['lines'], $productData);
        if ($ts >= $thisStart) $cur += $total;
        elseif ($ts >= $prevStart && $ts < $prevEnd) $prev += $total;
    }
    return $prev > 0 ? ($cur - $prev) / $prev * 100 : null;
};
for ($try = 0; $try < 12; $try++) {
    $g = $growth();
    if ($g === null || ($g >= 11 && $g <= 17)) break; // aim mid-range of +8..20%
    // Split the correction between both months (sqrt each way) so no
    // single month gets oversized tickets.
    $factor = sqrt(max(0.5, min(2.0, 1.14 / (1 + $g / 100))));
    foreach ($salesPlan as &$s) {
        $ts = strtotime(at($s['days'], $s['hour']));
        if ($ts >= $thisStart) $f = $factor;
        elseif ($ts >= $prevStart && $ts < $prevEnd) $f = 1 / $factor;
        else continue;
        foreach ($s['lines'] as &$line) {
            $line[1] = max(1, (int) round($line[1] * $f));
        }
        unset($line);
    }
    unset($s);
}
$plannedGrowth = $growth();

// ───────────────────────── 5. The purchases plan ─────────────────────────
// [days ago, supplier, [[product, qty]...], status, delivery, payment]
// delivery: on_time | late (received after the expected date) | pending
$purchasesPlan = [
    [57, 'cafe',    [['cafe', 60], ['the', 48]],                         'received', 'on_time', 'paid'],
    [52, 'sotupro', [['huile', 16], ['sucre', 150], ['pates', 200]],     'received', 'on_time', 'paid'],
    [46, 'danone',  [['lait', 120], ['yaourt', 30], ['jus', 36]],        'received', 'late',    'paid'],
    [40, 'emball',  [['sacs', 30], ['savon', 12], ['essuie', 24]],       'received', 'on_time', 'paid'],
    [33, 'sotupro', [['tomate', 36], ['harissa', 48], ['semoule', 20]],  'received', 'late',    'paid'],
    [26, 'cafe',    [['cafe', 48], ['the', 24]],                         'received', 'on_time', 'paid'],
    [19, 'danone',  [['lait', 96], ['jus', 24]],                         'received', 'on_time', 'paid'],
    [17, 'sotupro', [['huile', 12], ['sucre', 100], ['pates', 120]],     'received', 'late',    'unpaid'],
    [ 8, 'emball',  [['sacs', 20], ['savon', 8]],                        'received', 'on_time', 'unpaid'],
    [ 4, 'cafe',    [['cafe', 36], ['the', 24]],                         'received', 'on_time', 'unpaid'],
    [ 9, 'sotupro', [['semoule', 40], ['tomate', 24], ['eau', 60]],      'draft',    'pending', 'unpaid'],
];

// ──────────── 6. Opening stock, worked out so everything adds up ────────────
$sold = $received = [];
foreach (array_keys($productData) as $key) $sold[$key] = $received[$key] = 0;
foreach ($salesPlan as $s) foreach ($s['lines'] as [$key, $qty]) $sold[$key] += $qty;
foreach ($purchasesPlan as $p) {
    if ($p[3] !== 'received') continue;
    foreach ($p[2] as [$key, $qty]) $received[$key] += $qty;
}
$corrections = [['cafe', 2, 27, 'Correction d\'inventaire'], ['savon', -1, 9, 'Casse constatée']];
$correction = array_fill_keys(array_keys($productData), 0);
foreach ($corrections as [$key, $qty]) $correction[$key] += $qty;

$opening = [];
foreach ($productData as $key => $p) {
    // final = opening + received + corrections − sold  →  opening = …
    $opening[$key] = $p[10] + $sold[$key] - $received[$key] - $correction[$key];
    if ($opening[$key] < 0) {
        // Not enough stock to cover the plan: top up the opening stock and
        // accept a slightly higher final figure (still within its role).
        $opening[$key] = 0;
    }
}

// ──────────────────── 7. Products and opening stock ────────────────────
$products = [];
$productStmt = $pdo->prepare(
    'INSERT INTO products (id, tenant_id, sku, category_id, default_supplier_id, name, barcode,
                           price, cost, vat_rate, min_threshold, is_active, unit,
                           purchase_unit, units_per_purchase)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
);
$moveStmt = $pdo->prepare(
    'INSERT INTO stock_movements (id, tenant_id, product_id, warehouse_id, type, quantity, note, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$i = 0;
foreach ($productData as $key => $p) {
    $i++;
    $products[$key] = uuid();
    $productStmt->execute([
        $products[$key], $tenantId, sprintf('PRD-%03d', $i), $categories[$p[1]], $suppliers[$p[2]],
        $p[0], sprintf('619%010d', 1000000 + $i), $p[4], $p[3], $p[5], $p[9], $p[6], $p[7], $p[8],
    ]);
    if ($opening[$key] > 0) {
        $moveStmt->execute([uuid(), $tenantId, $products[$key], $warehouses['principal'],
            'in', $opening[$key], 'Stock initial', at(61, '08:00')]);
    }
}
echo 'Products: ' . count($products) . "\n";

// ───────────────────────── 8. Purchases ─────────────────────────
$purchaseStmt = $pdo->prepare(
    'INSERT INTO purchases (id, tenant_id, supplier_id, warehouse_id, reference, expected_date,
                            received_date, subtotal_ht, total_vat, total, status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$purchaseLineStmt = $pdo->prepare(
    'INSERT INTO purchase_lines (id, purchase_id, product_id, quantity, unit_cost, vat_rate, line_total, vat_amount)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$paymentStmt = $pdo->prepare(
    'INSERT INTO payments (id, tenant_id, sale_id, purchase_id, amount, method, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);

usort($purchasesPlan, fn($a, $b) => $b[0] <=> $a[0]);
$audit = []; // [action, entity type, entity id, when, details, who] for section 11
$year = date('Y');
$n = 0;
foreach ($purchasesPlan as [$daysAgo, $supplierKey, $lines, $status, $delivery, $payment]) {
    $n++;
    $id = uuid();
    $createdAt = at($daysAgo, hourBetween(7, 11));
    $lead = $supplierData[$supplierKey][7];
    $expected = date('Y-m-d', strtotime(dateOnly($createdAt) . " +$lead days"));
    if ($delivery === 'pending') {
        // Ordered 9 days ago, expected 6 days ago: a late pending delivery.
        $expected = date('Y-m-d', strtotime('-6 days'));
    }
    $receivedDate = match ($delivery) {
        'on_time' => date('Y-m-d', strtotime($expected . ' -' . rnd(0, 1) . ' days')),
        'late'    => date('Y-m-d', strtotime($expected . ' +' . rnd(2, 5) . ' days')),
        default   => null,
    };
    if ($receivedDate !== null && $receivedDate > date('Y-m-d')) $receivedDate = date('Y-m-d');

    $sub = $vat = 0;
    $rows = [];
    foreach ($lines as [$key, $qty]) {
        $cost = $productData[$key][3];
        $rate = $productData[$key][5];
        $lt = money($qty * $cost);
        $va = money($lt * $rate / 100);
        $sub += $lt;
        $vat += $va;
        $rows[] = [$key, $qty, $cost, $rate, $lt, $va];
    }
    $total = money($sub + $vat);
    $purchaseStmt->execute([$id, $tenantId, $suppliers[$supplierKey], $warehouses['principal'],
        sprintf('BC-%s-%04d', $year, $n), $expected, $receivedDate,
        money($sub), money($vat), $total, $status, $createdAt]);

    foreach ($rows as [$key, $qty, $cost, $rate, $lt, $va]) {
        $purchaseLineStmt->execute([uuid(), $id, $products[$key], $qty, $cost, $rate, $lt, $va]);
        if ($status === 'received') {
            $moveStmt->execute([uuid(), $tenantId, $products[$key], $warehouses['principal'],
                'in', $qty, "Purchase $id", $receivedDate . ' 10:30:00']);
        }
    }
    $audit[] = ['create_purchase', 'purchase', $id, $createdAt, ['total' => $total], 'stock'];
    if ($receivedDate !== null) {
        $audit[] = ['receive_purchase', 'purchase', $id, $receivedDate . ' 10:31:00', null, 'stock'];
    }
    if ($payment === 'paid') {
        $paidAt = plusDays($createdAt, $supplierData[$supplierKey][6] - rnd(0, 3));
        $paymentId = uuid();
        $paymentStmt->execute([$paymentId, $tenantId, null, $id, $total, 'bank_transfer', $paidAt]);
        $audit[] = ['record_payment', 'payment', $paymentId, $paidAt, ['amount' => $total], 'admin'];
    }
}
echo 'Purchases: ' . count($purchasesPlan) . "\n";

// ───────────────────────── 9. Sales and customer payments ─────────────────────────
$saleStmt = $pdo->prepare(
    'INSERT INTO sales (id, tenant_id, customer_id, warehouse_id, reference, due_date,
                        subtotal_ht, total_vat, total, status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$saleLineStmt = $pdo->prepare(
    'INSERT INTO sale_lines (id, sale_id, product_id, quantity, unit_price, vat_rate, line_total, vat_amount)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

$overdueBudget = 5;   // past due and unpaid
$partialBudget = 6;   // partly paid
$today = date('Y-m-d');
$stats = ['paid' => 0, 'partial' => 0, 'overdue' => 0, 'open' => 0];
$n = 0;
foreach ($salesPlan as $s) {
    $n++;
    $id = uuid();
    $createdAt = at($s['days'], $s['hour']);
    $terms = $customerData[$s['customer']][7];
    $due = $terms > 0 ? date('Y-m-d', strtotime(dateOnly($createdAt) . " +$terms days")) : null;

    $sub = $vat = 0;
    $rows = [];
    foreach ($s['lines'] as [$key, $qty]) {
        $price = $productData[$key][4];
        $rate = $productData[$key][5];
        $lt = money($qty * $price);
        $va = money($lt * $rate / 100);
        $sub += $lt;
        $vat += $va;
        $rows[] = [$key, $qty, $price, $rate, $lt, $va];
    }
    $total = money($sub + $vat);
    $saleStmt->execute([$id, $tenantId, $customers[$s['customer']], $warehouses['principal'],
        sprintf('FAC-%s-%04d', $year, $n), $due, money($sub), money($vat), $total, 'confirmed', $createdAt]);
    foreach ($rows as [$key, $qty, $price, $rate, $lt, $va]) {
        $saleLineStmt->execute([uuid(), $id, $products[$key], $qty, $price, $rate, $lt, $va]);
        $moveStmt->execute([uuid(), $tenantId, $products[$key], $warehouses['principal'],
            'out', $qty, "Sale $id", $createdAt]);
    }

    // How this invoice was paid.
    $pastDue = $due === null || $due < $today;
    $roll = rnd(1, 100);
    if ($terms === 0) {
        $mode = 'paid'; // walk-in customer pays at the counter
    } elseif ($pastDue && $overdueBudget > 0 && $roll <= 30 && $s['customer'] !== 'oliviers') {
        $mode = 'overdue';
        $overdueBudget--;
    } elseif ($partialBudget > 0 && $roll > 82) {
        $mode = 'partial';
        $partialBudget--;
    } elseif (!$pastDue && $roll <= 45) {
        $mode = 'open'; // not due yet, not paid yet: normal
    } else {
        $mode = 'paid';
    }
    $stats[$mode]++;
    $audit[] = ['create_sale', 'sale', $id, $createdAt, ['total' => $total], 'sales'];

    if ($mode === 'paid') {
        $paidAt = $terms === 0 ? $createdAt : plusDays($createdAt, min(rnd(2, max(2, $terms)), max(0, $s['days'])));
        $paymentId = uuid();
        $paymentStmt->execute([$paymentId, $tenantId, $id, null, $total,
            $terms === 0 ? 'cash' : (rnd(0, 1) ? 'bank_transfer' : 'check'), $paidAt]);
        $audit[] = ['record_payment', 'payment', $paymentId, $paidAt, ['amount' => $total], 'sales'];
    } elseif ($mode === 'partial') {
        $paidAt = plusDays($createdAt, min(3, $s['days']));
        $amount = money($total * rnd(30, 60) / 100);
        $paymentId = uuid();
        $paymentStmt->execute([$paymentId, $tenantId, $id, null, $amount, 'check', $paidAt]);
        $audit[] = ['record_payment', 'payment', $paymentId, $paidAt, ['amount' => $amount], 'admin'];
    }
}
echo "Sales: " . count($salesPlan) . " (paid {$stats['paid']}, partial {$stats['partial']}, "
   . "overdue {$stats['overdue']}, not yet due {$stats['open']})\n";

// ──────────────── 10. Corrections and one transfer ────────────────
foreach ($corrections as [$key, $qty, $daysAgo, $note]) {
    $moveStmt->execute([uuid(), $tenantId, $products[$key], $warehouses['principal'],
        $qty >= 0 ? 'in' : 'out', abs($qty), $note, at($daysAgo, '17:30')]);
}
$moveStmt->execute([uuid(), $tenantId, $products['sucre'], $warehouses['principal'],
    'out', 20, 'Transfert vers Sakiet Ezzit', at(13, '13:00')]);
$moveStmt->execute([uuid(), $tenantId, $products['sucre'], $warehouses['sakiet'],
    'in', 20, 'Transfert vers Sakiet Ezzit', at(13, '13:05')]);

// ──────────────── 10b. Audit trail: ~30 entries over the last days ────────────────
// Built from what was actually inserted above (real sale, payment and
// purchase ids and amounts), plus daily sign-ins, a stock correction, a
// customer update and a report download, so the Audit log reads like a
// real week of work.
$userIds = $pdo->query("SELECT email, id FROM users WHERE tenant_id = $t")->fetchAll(PDO::FETCH_KEY_PAIR);
$who = [
    'admin' => $userIds[DEMO_ADMIN] ?? $adminId,
    'stock' => $userIds['stock@elbaraka.tn'] ?? $adminId,
    'sales' => $userIds['ventes@elbaraka.tn'] ?? $adminId,
];
$nowTs = time();
$recent = array_values(array_filter($audit,
    fn($a) => strtotime($a[3]) >= strtotime('-6 days') && strtotime($a[3]) <= $nowTs));
usort($recent, fn($a, $b) => strcmp($b[3], $a[3]));
$entries = array_slice($recent, 0, 18);

// Each user signs in on most mornings.
for ($d = 0; $d <= 5; $d++) {
    foreach (['admin' => '08', 'sales' => '08', 'stock' => '07'] as $role => $h) {
        $when = date('Y-m-d', strtotime("-$d days")) . sprintf(' %s:%02d:00', $h, rnd(10, 55));
        if (strtotime($when) > $nowTs || rnd(1, 100) > 60) continue;
        $entries[] = ['login', 'user', $who[$role], $when, null, $role];
    }
}
$entries[] = ['stock_movement', 'stock_movement', null, at(2, '17:30'), ['type' => 'out', 'quantity' => 1], 'stock'];
$entries[] = ['update_customer', 'customer', $customers['golfe'], at(3, '11:20'), ['name' => $customerData['golfe'][0]], 'sales'];
$entries[] = ['generate_report', 'report', null, at(1, '18:05'), ['type' => 'director'], 'admin'];
$entries = array_slice($entries, 0, 32);

$auditStmt = $pdo->prepare(
    'INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, details, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ($entries as [$action, $type, $entityId, $when, $details, $role]) {
    $auditStmt->execute([uuid(), $tenantId, $who[$role], $action, $type, $entityId,
        $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, $when]);
}
echo 'Audit entries: ' . count($entries) . "\n";

$pdo->commit();

// ─────────────────── 11. What the demo will show ───────────────────
echo str_repeat('-', 56) . "\n";
$q = fn(string $sql) => $pdo->query($sql)->fetchColumn();
$revenueMonth = $q("SELECT COALESCE(SUM(total),0) FROM sales WHERE tenant_id=$t
                    AND YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())");
$today = $q("SELECT COUNT(*) FROM sales WHERE tenant_id=$t AND DATE(created_at)=CURDATE()");
$stock = $pdo->query(
    "SELECT pr.name, pr.min_threshold,
            COALESCE(SUM(CASE WHEN sm.type='in' THEN sm.quantity WHEN sm.type='out' THEN -sm.quantity ELSE 0 END),0) AS qty
     FROM products pr LEFT JOIN stock_movements sm ON sm.product_id = pr.id
     WHERE pr.tenant_id=$t GROUP BY pr.id HAVING qty <= pr.min_threshold ORDER BY qty"
)->fetchAll(PDO::FETCH_ASSOC);
printf("Revenue this month : %s DT\n", number_format((float) $revenueMonth, 2, '.', ' '));
printf("Sales today        : %d\n", $today);
printf("This month vs last : %+.1f%% (same day range, as on the dashboard)\n", $plannedGrowth ?? 0);
foreach ($stock as $s) {
    printf("%-19s: %s (%d left, min %d)\n", (int) $s['qty'] <= 0 ? 'Out of stock' : 'Low stock',
        $s['name'], (int) $s['qty'], (int) $s['min_threshold']);
}
printf("Dormant products   : %s\n", implode(', ', array_map(fn($k) => $productData[$k][0], $dormant)));
echo "Done. Sign in with " . DEMO_ADMIN . " / " . DEMO_PASSWORD . " (if the users were created by this script).\n";
