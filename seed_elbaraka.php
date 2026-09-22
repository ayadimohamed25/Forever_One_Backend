<?php
/**
 * Seeds the database with a realistic Tunisian SME: Épicerie El Baraka (Sfax).
 *
 * Every figure is derived, not invented twice: opening stock is computed from
 * the sales, purchases and corrections below so the dashboard alerts, the
 * receivables and the stock value are guaranteed to agree with each other.
 *
 * WARNING: empties every table first. Demo database only.
 * Usage:  php seed_elbaraka.php
 */

require __DIR__ . '/vendor/autoload.php';

use App\Config\Database;
use App\Core\Uuid;

Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function uuid(): string { return Uuid::generate(); }
function ago(int $days, string $hm = '10:15'): string {
    return date('Y-m-d ' . $hm . ':00', strtotime("-$days days"));
}
function plus(string $datetime, int $days): string {
    return date('Y-m-d H:i:s', strtotime($datetime . " +$days days"));
}
function money(float $v): float { return round($v, 2); }

echo "Épicerie El Baraka — seed\n";
echo str_repeat('=', 52) . "\n";

// ─────────────────────────── 1. Wipe ───────────────────────────
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec("TRUNCATE TABLE `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "Tables emptied\n";

// ─────────────────────── 2. Tenant & users ───────────────────────
$tenantId = uuid();
$pdo->prepare('INSERT INTO tenants (id, name) VALUES (?, ?)')
    ->execute([$tenantId, 'Épicerie El Baraka']);

$password = password_hash('baraka2026', PASSWORD_BCRYPT);
$userStmt = $pdo->prepare(
    'INSERT INTO users (id, tenant_id, email, password_hash, full_name, phone, role, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
);
$adminId = uuid();
$userStmt->execute([$adminId, $tenantId, 'admin@elbaraka.tn', $password, 'Mohamed Trabelsi', '74 401 220', 'admin']);
$userStmt->execute([uuid(), $tenantId, 'stock@elbaraka.tn', $password, 'Sonia Ben Amor', '98 214 663', 'stock']);
$userStmt->execute([uuid(), $tenantId, 'ventes@elbaraka.tn', $password, 'Karim Jlassi', '22 760 148', 'commercial']);
echo "Company and 3 users created\n";

// ───────────────────────── 3. Categories ─────────────────────────
$categoryData = [
    'boissons'  => ['Boissons', 'Cafés, thés, eaux et jus', '#C8553D'],
    'epicerie'  => ['Épicerie sèche', 'Huiles, semoules, pâtes et conserves', '#A16207'],
    'frais'     => ['Produits frais', 'Lait, yaourts et dérivés', '#3F7D4E'],
    'hygiene'   => ['Hygiène', 'Savons et papiers', '#8F8078'],
    'emballage' => ['Emballage', 'Sacs, barquettes et cartons', '#2A1F1A'],
];
$categories = [];
$stmt = $pdo->prepare('INSERT INTO categories (id, tenant_id, name, description, color) VALUES (?, ?, ?, ?, ?)');
foreach ($categoryData as $key => [$name, $description, $color]) {
    $categories[$key] = uuid();
    $stmt->execute([$categories[$key], $tenantId, $name, $description, $color]);
}

// ───────────────────────── 4. Warehouses ─────────────────────────
$warehouses = ['principal' => uuid(), 'sakiet' => uuid()];
$stmt = $pdo->prepare(
    'INSERT INTO warehouses (id, tenant_id, code, name, location, address, manager_name, phone, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
);
$stmt->execute([$warehouses['principal'], $tenantId, 'DEP-01', 'Dépôt Principal', 'Sfax',
    'Route de Gabès km 2, Sfax', 'Sonia Ben Amor', '74 401 221']);
$stmt->execute([$warehouses['sakiet'], $tenantId, 'DEP-02', 'Dépôt Sakiet Ezzit', 'Sfax',
    'Zone industrielle Sakiet Ezzit, Sfax', 'Karim Jlassi', '74 401 222']);

// ───────────────────────── 5. Suppliers ─────────────────────────
$supplierData = [
    'cafe'    => ['Société Tunisienne de Café', 'Hichem Gharbi', '71 234 510', 'contact@stcafe.tn',
                  'Rue de Carthage, Tunis', '1122334/A/M/000', 14, 7, 'TN59 1000 6035 0000 1234 5678'],
    'danone'  => ['Délice Danone Distribution', 'Fatma Khelifi', '71 880 400', 'commandes@delice.tn',
                  'Avenue Habib Bourguiba, Sousse', '2233445/B/M/000', 30, 3, 'TN59 0400 3021 0000 9876 5432'],
    'sotupro' => ['SOTUPRODIS Sfax', 'Anis Mahjoub', '74 612 330', 'anis.mahjoub@sotuprodis.tn',
                  'Zone industrielle Poudrière, Sfax', '3344556/C/M/000', 7, 2, 'TN59 0800 1044 0000 4455 6677'],
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

// ───────────────────────── 6. Customers ─────────────────────────
$customerData = [
    'majestic' => ['Café Majestic', 'company', '74 225 880', 'majestic.sfax@gmail.com',
                   'Avenue Hédi Chaker, Sfax', '5566778/E/M/000', 5000, 30],
    'golfe'    => ['Restaurant Le Golfe', 'company', '74 448 190', 'contact@legolfe.tn',
                   'Corniche, Sfax', '6677889/F/M/000', 3000, 15],
    'ennour'   => ['Supérette Ennour', 'company', '74 371 600', 'superette.ennour@gmail.com',
                   'Cité El Habib, Sfax', '7788990/G/M/000', 8000, 30],
    'oliviers' => ['Hôtel Les Oliviers', 'company', '74 225 000', 'achats@lesoliviers.tn',
                   'Avenue Habib Thameur, Sfax', '8899001/H/M/000', 12000, 45],
    'mongi'    => ['Mongi Sassi', 'individual', '98 443 217', null,
                   'Rue Mongi Slim, Sfax', null, 0, 0],
    'ali'      => ['Snack Chez Ali', 'company', '25 118 740', null,
                   'Route de l\'Aéroport, Sfax', '9900112/I/M/000', 2000, 15],
];
$customers = [];
$stmt = $pdo->prepare(
    'INSERT INTO customers (id, tenant_id, name, customer_type, phone, email, address, tax_id,
                            credit_limit, payment_terms_days)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ($customerData as $key => $c) {
    $customers[$key] = uuid();
    $stmt->execute(array_merge([$customers[$key], $tenantId], $c));
}

// ───────────────────────── 7. Products ─────────────────────────
// key => [name, category, supplier, cost, price HT, VAT, unit, purchase unit,
//         units/purchase, target stock, min threshold]
$productData = [
    'cafe'    => ['Café moulu Bondin 500g',          'boissons',  'cafe',    8.200, 12.500, 13, 'paquet', 'carton', 12,  45, 20],
    'the'     => ['Thé vert Sultan 250g',            'boissons',  'cafe',    4.100,  6.900, 13, 'boîte',  'carton', 24,  62, 25],
    'eau'     => ['Eau minérale Safia 1.5L (pack 6)','boissons',  'sotupro', 3.600,  5.400, 19, 'pack',   'palette',60,   8, 30],
    'huile'   => ['Huile d\'olive extra vierge 5L',  'epicerie',  'sotupro',62.000, 89.000, 19, 'bidon',  'carton',  4,  24, 10],
    'semoule' => ['Semoule fine 5kg',                'epicerie',  'sotupro', 9.500, 13.800,  7, 'sac',    'palette',20,   0, 15],
    'sucre'   => ['Sucre blanc 1kg',                 'epicerie',  'sotupro', 1.900,  2.600,  7, 'paquet', 'sac',    25, 150, 50],
    'pates'   => ['Pâtes spaghetti 500g',            'epicerie',  'sotupro', 1.250,  1.900,  7, 'paquet', 'carton', 20, 210, 60],
    'tomate'  => ['Concentré de tomate 800g',        'epicerie',  'sotupro', 3.400,  4.900, 19, 'boîte',  'carton', 12,   3, 20],
    'lait'    => ['Lait demi-écrémé 1L',             'frais',     'danone',  1.350,  1.750,  7, 'brique', 'pack',    6,  96, 40],
    'yaourt'  => ['Yaourt nature (pack 8)',          'frais',     'danone',  3.800,  5.200,  7, 'pack',   'carton',  6,  34, 15],
    'savon'   => ['Savon liquide 5L',                'hygiene',   'emball', 14.500, 21.000, 19, 'bidon',  'carton',  4,  18,  8],
    'essuie'  => ['Papier essuie-tout (pack 6)',     'hygiene',   'emball',  6.700,  9.900, 19, 'pack',   'carton',  8,  27, 12],
    'sacs'    => ['Sacs kraft 1kg (x100)',           'emballage', 'emball', 11.000, 16.500, 19, 'carton', 'palette',30,  41, 15],
    'barq'    => ['Barquettes alu (x50)',            'emballage', 'emball',  8.900, 13.200, 19, 'carton', 'palette',40,   0, 10],
];

// ───────────────────────── 8. Movements plan ─────────────────────────
// [daysAgo, hour, customer, [[product, qty], ...], payment, partial amount]
$salesPlan = [
    [58, '09:20', 'ennour',   [['huile', 8], ['sucre', 50], ['pates', 60]],                 'paid',    0],
    [52, '11:05', 'majestic', [['cafe', 30], ['the', 20], ['sucre', 20]],                   'paid',    0],
    [45, '15:40', 'ali',      [['pates', 40], ['tomate', 20], ['lait', 30]],                'paid',    0],
    [40, '10:10', 'golfe',    [['huile', 4], ['tomate', 24], ['semoule', 10]],              'paid',    0],
    [35, '16:25', 'oliviers', [['cafe', 25], ['yaourt', 20], ['lait', 60], ['essuie', 10]], 'paid',    0],
    [30, '08:50', 'mongi',    [['cafe', 2], ['sucre', 20], ['pates', 15], ['lait', 12]],    'paid',    0],
    [26, '14:15', 'ennour',   [['sacs', 20], ['barq', 15], ['essuie', 12]],                 'paid',    0],
    [23, '09:45', 'majestic', [['cafe', 40], ['the', 25], ['eau', 30]],                     'partial', 500],
    [20, '11:30', 'oliviers', [['huile', 10], ['savon', 8], ['essuie', 15]],                'paid',    0],
    [18, '10:00', 'ennour',   [['sucre', 80], ['pates', 100], ['semoule', 20], ['lait', 50]],'unpaid',  0],
    [16, '15:10', 'golfe',    [['huile', 6], ['tomate', 30], ['semoule', 15]],              'unpaid',  0],
    [14, '09:35', 'majestic', [['cafe', 35], ['the', 30], ['eau', 25]],                     'paid',    0],
    [12, '17:00', 'ali',      [['barq', 25], ['sacs', 15], ['tomate', 15]],                 'partial', 300],
    [10, '10:45', 'oliviers', [['huile', 12], ['yaourt', 40], ['lait', 80], ['savon', 6]],  'unpaid',  0],
    [ 8, '12:20', 'ennour',   [['cafe', 20], ['sucre', 60], ['pates', 80], ['sacs', 10]],   'paid',    0],
    [ 6, '08:30', 'mongi',    [['cafe', 6], ['sucre', 15], ['lait', 10]],                   'paid',    0],
    [ 4, '16:05', 'golfe',    [['huile', 5], ['savon', 4], ['essuie', 8], ['barq', 10]],    'partial', 400],
    [ 2, '11:50', 'majestic', [['cafe', 45], ['the', 35], ['yaourt', 25], ['eau', 20]],     'unpaid',  0],
];

// [daysAgo, hour, supplier, [[product, qty], ...], status, payment]
$purchasesPlan = [
    [55, '08:15', 'cafe',    [['cafe', 100], ['the', 60]],                        'received', 'paid'],
    [48, '09:30', 'sotupro', [['huile', 20], ['semoule', 40], ['sucre', 200]],    'received', 'paid'],
    [41, '07:50', 'danone',  [['lait', 180], ['yaourt', 60]],                     'received', 'paid'],
    [33, '14:00', 'emball',  [['sacs', 60], ['barq', 40], ['savon', 20]],         'received', 'paid'],
    [25, '10:20', 'sotupro', [['pates', 400], ['tomate', 60], ['eau', 60]],       'received', 'paid'],
    [17, '08:40', 'cafe',    [['cafe', 80], ['the', 50]],                         'received', 'paid'],
    [11, '07:45', 'danone',  [['lait', 140], ['yaourt', 55]],                     'received', 'unpaid'],
    [ 5, '15:25', 'emball',  [['essuie', 50], ['sacs', 20]],                      'received', 'unpaid'],
    [ 2, '09:10', 'sotupro', [['huile', 30], ['semoule', 50], ['sucre', 300]],    'draft',    'unpaid'],
];

// [product, signed qty, daysAgo, note]
$corrections = [
    ['cafe',   3, 27, 'Correction d\'inventaire'],
    ['savon', -2,  9, 'Casse constatée'],
];
// One transfer between the two warehouses.
$transfer = ['sucre', 20, 13, 'Transfert vers Sakiet Ezzit'];

// ──────────── 9. Opening stock, worked out from the plan ────────────
$sold = $received = $corrIn = $corrOut = [];
foreach (array_keys($productData) as $key) {
    $sold[$key] = $received[$key] = $corrIn[$key] = $corrOut[$key] = 0;
}
foreach ($salesPlan as $sale) {
    foreach ($sale[3] as [$key, $qty]) $sold[$key] += $qty;
}
foreach ($purchasesPlan as $purchase) {
    if ($purchase[4] !== 'received') continue;          // a draft moves nothing
    foreach ($purchase[3] as [$key, $qty]) $received[$key] += $qty;
}
foreach ($corrections as [$key, $qty]) {
    if ($qty >= 0) $corrIn[$key] += $qty; else $corrOut[$key] += abs($qty);
}

$opening = [];
foreach ($productData as $key => $p) {
    $target = $p[9];
    // final = opening + received + corrections in − sold − corrections out
    $opening[$key] = $target + $sold[$key] + $corrOut[$key] - $received[$key] - $corrIn[$key];

    if ($opening[$key] < 0) {
        exit("\nAborted: '{$p[0]}' would need a negative opening stock ({$opening[$key]}).\n"
           . "Lower its sales or raise its purchases in the plan above.\n");
    }
}

// ──────────────────── 10. Insert products & opening ────────────────────
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

$index = 0;
foreach ($productData as $key => $p) {
    [$name, $categoryKey, $supplierKey, $cost, $price, $vat, $unit, $purchaseUnit, $perPurchase] = $p;
    $index++;
    $products[$key] = uuid();

    $productStmt->execute([
        $products[$key], $tenantId,
        sprintf('PRD-%03d', $index),
        $categories[$categoryKey],
        $suppliers[$supplierKey],
        $name,
        sprintf('619%010d', 1000000 + $index),
        $price, $cost, $vat, $p[10],
        $unit, $purchaseUnit, $perPurchase,
    ]);

    if ($opening[$key] > 0) {
        $moveStmt->execute([
            uuid(), $tenantId, $products[$key], $warehouses['principal'],
            'in', $opening[$key], 'Stock initial', ago(60, '08:00'),
        ]);
    }
}
echo 'Products created: ' . count($products) . "\n";

// ───────────────────────── 11. Purchases ─────────────────────────
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

$purchaseNo = 0;
foreach ($purchasesPlan as [$daysAgo, $hour, $supplierKey, $lines, $status, $payment]) {
    $purchaseNo++;
    $purchaseId = uuid();
    $createdAt = ago($daysAgo, $hour);
    $leadTime = $supplierData[$supplierKey][8];

    $subtotal = $vatTotal = 0;
    $rows = [];
    foreach ($lines as [$key, $qty]) {
        $cost = $productData[$key][3];
        $vatRate = $productData[$key][5];
        $lineTotal = money($qty * $cost);
        $vatAmount = money($lineTotal * $vatRate / 100);
        $subtotal += $lineTotal;
        $vatTotal += $vatAmount;
        $rows[] = [$key, $qty, $cost, $vatRate, $lineTotal, $vatAmount];
    }
    $total = money($subtotal + $vatTotal);

    $purchaseStmt->execute([
        $purchaseId, $tenantId, $suppliers[$supplierKey], $warehouses['principal'],
        sprintf('BC-2026-%04d', $purchaseNo),
        date('Y-m-d', strtotime($createdAt . " +$leadTime days")),
        $status === 'received' ? date('Y-m-d', strtotime($createdAt)) : null,
        money($subtotal), money($vatTotal), $total, $status, $createdAt,
    ]);

    foreach ($rows as [$key, $qty, $cost, $vatRate, $lineTotal, $vatAmount]) {
        $purchaseLineStmt->execute([
            uuid(), $purchaseId, $products[$key], $qty, $cost, $vatRate, $lineTotal, $vatAmount,
        ]);
        if ($status === 'received') {
            $moveStmt->execute([
                uuid(), $tenantId, $products[$key], $warehouses['principal'],
                'in', $qty, "Purchase $purchaseId", $createdAt,
            ]);
        }
    }

    if ($payment === 'paid') {
        $paymentStmt->execute([
            uuid(), $tenantId, null, $purchaseId, $total, 'bank_transfer', plus($createdAt, 3),
        ]);
    }
}
echo 'Purchases created: ' . count($purchasesPlan) . "\n";

// ───────────────────────── 12. Sales ─────────────────────────
$saleStmt = $pdo->prepare(
    'INSERT INTO sales (id, tenant_id, customer_id, warehouse_id, reference, due_date,
                        subtotal_ht, total_vat, total, status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$saleLineStmt = $pdo->prepare(
    'INSERT INTO sale_lines (id, sale_id, product_id, quantity, unit_price, vat_rate, line_total, vat_amount)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

$saleNo = 0;
foreach ($salesPlan as [$daysAgo, $hour, $customerKey, $lines, $payment, $partialAmount]) {
    $saleNo++;
    $saleId = uuid();
    $createdAt = ago($daysAgo, $hour);
    $terms = $customerData[$customerKey][7];

    $subtotal = $vatTotal = 0;
    $rows = [];
    foreach ($lines as [$key, $qty]) {
        $price = $productData[$key][4];
        $vatRate = $productData[$key][5];
        $lineTotal = money($qty * $price);
        $vatAmount = money($lineTotal * $vatRate / 100);
        $subtotal += $lineTotal;
        $vatTotal += $vatAmount;
        $rows[] = [$key, $qty, $price, $vatRate, $lineTotal, $vatAmount];
    }
    $total = money($subtotal + $vatTotal);

    $saleStmt->execute([
        $saleId, $tenantId, $customers[$customerKey], $warehouses['principal'],
        sprintf('FAC-2026-%04d', $saleNo),
        $terms > 0 ? date('Y-m-d', strtotime($createdAt . " +$terms days")) : null,
        money($subtotal), money($vatTotal), $total, 'confirmed', $createdAt,
    ]);

    foreach ($rows as [$key, $qty, $price, $vatRate, $lineTotal, $vatAmount]) {
        $saleLineStmt->execute([
            uuid(), $saleId, $products[$key], $qty, $price, $vatRate, $lineTotal, $vatAmount,
        ]);
        $moveStmt->execute([
            uuid(), $tenantId, $products[$key], $warehouses['principal'],
            'out', $qty, "Sale $saleId", $createdAt,
        ]);
    }

    if ($payment === 'paid') {
        $paymentStmt->execute([
            uuid(), $tenantId, $saleId, null, $total,
            $customerKey === 'mongi' ? 'cash' : 'bank_transfer', plus($createdAt, 2),
        ]);
    } elseif ($payment === 'partial') {
        $paymentStmt->execute([
            uuid(), $tenantId, $saleId, null, money($partialAmount), 'check', plus($createdAt, 2),
        ]);
    }
}
echo 'Sales created: ' . count($salesPlan) . "\n";

// ──────────────── 13. Corrections and transfer ────────────────
foreach ($corrections as [$key, $qty, $daysAgo, $note]) {
    $moveStmt->execute([
        uuid(), $tenantId, $products[$key], $warehouses['principal'],
        $qty >= 0 ? 'in' : 'out', abs($qty), $note, ago($daysAgo, '17:30'),
    ]);
}
[$transferKey, $transferQty, $transferDays, $transferNote] = $transfer;
$moveStmt->execute([uuid(), $tenantId, $products[$transferKey], $warehouses['principal'],
    'out', $transferQty, $transferNote, ago($transferDays, '13:00')]);
$moveStmt->execute([uuid(), $tenantId, $products[$transferKey], $warehouses['sakiet'],
    'in', $transferQty, $transferNote, ago($transferDays, '13:05')]);

// ─────────────────── 14. Check what the app will show ───────────────────
echo str_repeat('-', 52) . "\n";

$row = $pdo->query(
    "SELECT COALESCE(SUM(s.total), 0) AS revenue
     FROM sales s WHERE s.tenant_id = '$tenantId'
       AND YEAR(s.created_at) = YEAR(NOW()) AND MONTH(s.created_at) = MONTH(NOW())"
)->fetch(PDO::FETCH_ASSOC);
printf("Revenue this month : %10.2f DT\n", $row['revenue']);

$row = $pdo->query(
    "SELECT COALESCE(SUM(s.total), 0) - COALESCE((
              SELECT SUM(p.amount) FROM payments p
              JOIN sales s2 ON s2.id = p.sale_id WHERE s2.tenant_id = '$tenantId'), 0) AS due
     FROM sales s WHERE s.tenant_id = '$tenantId'"
)->fetch(PDO::FETCH_ASSOC);
printf("Receivables        : %10.2f DT\n", $row['due']);

$row = $pdo->query(
    "SELECT COALESCE(SUM(pu.total), 0) - COALESCE((
              SELECT SUM(p.amount) FROM payments p
              JOIN purchases pu2 ON pu2.id = p.purchase_id WHERE pu2.tenant_id = '$tenantId'), 0) AS due
     FROM purchases pu WHERE pu.tenant_id = '$tenantId'"
)->fetch(PDO::FETCH_ASSOC);
printf("Payables           : %10.2f DT\n", $row['due']);

$stock = $pdo->query(
    "SELECT p.name, p.cost, p.min_threshold,
            COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity
                              WHEN sm.type = 'out' THEN -sm.quantity ELSE 0 END), 0) AS qty
     FROM products p
     LEFT JOIN stock_movements sm ON sm.product_id = p.id
     WHERE p.tenant_id = '$tenantId'
     GROUP BY p.id, p.name, p.cost, p.min_threshold ORDER BY p.name"
)->fetchAll(PDO::FETCH_ASSOC);

$stockValue = 0;
$out = $low = [];
foreach ($stock as $s) {
    $stockValue += $s['qty'] * $s['cost'];
    if ($s['qty'] <= 0) $out[] = $s['name'];
    elseif ($s['qty'] <= $s['min_threshold']) $low[] = $s['name'];
}
printf("Stock value        : %10.2f DT\n", $stockValue);
echo 'Out of stock       : ' . (count($out) . ' — ' . implode(', ', $out)) . "\n";
echo 'Low stock          : ' . (count($low) . ' — ' . implode(', ', $low)) . "\n";

$days = $pdo->query("SELECT COUNT(DISTINCT DATE(created_at)) FROM sales WHERE tenant_id = '$tenantId'")
    ->fetchColumn();
echo "Distinct sale days : $days\n";

echo str_repeat('=', 52) . "\n";
echo "Sign in with  admin@elbaraka.tn  /  baraka2026\n";