<?php
/**
 * GSTR-1 for one month, in the GST portal's format.
 * GET ?month=YYYY-MM&store_id=  → { json: <file for the GST offline tool / portal upload>, sheets: {b2b, b2cl, b2cs, hsn_b2b, hsn_b2c, docs}, summary }
 * Cancelled bills are left out of the tables and counted in "Documents issued". Check the file in the
 * GST offline tool before filing; credit / debit notes are not part of this app yet.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$user = require_auth();

$month = (string) ($_GET['month'] ?? date('Y-m', strtotime('first day of last month')));
if (!preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $month, $mm)) {
    fail('month must be like 2026-09');
}
$storeId = write_store($user, $_GET['store_id'] ?? null);
$store = get_store($storeId);
$from = "$month-01";
$to = date('Y-m-t', strtotime($from));
$fp = $mm[2] . $mm[1];
$b2clLimit = $from >= '2024-08-01' ? 100000 : 250000; // B2C Large: inter-state bills above this value

/** GST unit codes (UQC) for the units used in the app. */
function uqc(?string $unit): string
{
    $u = strtolower(trim((string) $unit));
    $map = ['pcs' => 'PCS', 'pc' => 'PCS', 'piece' => 'PCS', 'nos' => 'NOS', 'no' => 'NOS', 'kg' => 'KGS', 'kgs' => 'KGS', 'g' => 'GMS', 'gm' => 'GMS', 'gms' => 'GMS',
        'gram' => 'GMS', 'l' => 'LTR', 'ltr' => 'LTR', 'litre' => 'LTR', 'liter' => 'LTR', 'ml' => 'MLT', 'box' => 'BOX', 'pack' => 'PAC', 'pac' => 'PAC',
        'dozen' => 'DOZ', 'doz' => 'DOZ', 'set' => 'SET', 'bottle' => 'BTL', 'btl' => 'BTL', 'cup' => 'NOS', 'plate' => 'NOS', 'scoop' => 'NOS', 'tub' => 'NOS'];
    return $map[$u] ?? 'OTH';
}
$r2 = fn($v) => round((float) $v, 2);
$gdate = fn($d) => date('d-m-Y', strtotime($d));
$pos = fn($code) => str_pad((string) ($code ?: $store['state_code']), 2, '0', STR_PAD_LEFT);
$base = "i.store_id = ? AND i.invoice_date BETWEEN ? AND ? AND i.status <> 'Cancelled'";
$params = [$storeId, $from, $to];

// B2B: invoice by invoice, items grouped by GST rate.
$b2bRows = db_all(
    "SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.customer_gstin, i.customer_name, i.place_of_supply_code, i.reverse_charge, i.total_amount,
            ii.gst_rate, SUM(ii.taxable_value) AS txval, SUM(ii.igst_amount) AS iamt, SUM(ii.cgst_amount) AS camt, SUM(ii.sgst_amount) AS samt
     FROM invoices i JOIN invoice_items ii ON ii.invoice_id = i.invoice_id
     WHERE $base AND i.customer_gstin IS NOT NULL AND i.customer_gstin <> ''
     GROUP BY i.invoice_id, ii.gst_rate ORDER BY i.invoice_date, i.invoice_id, ii.gst_rate",
    $params
);
$b2b = [];
$sheetB2b = [];
foreach ($b2bRows as $r) {
    $ctin = strtoupper($r['customer_gstin']);
    $inv = &$b2b[$ctin][$r['invoice_id']];
    $inv ??= ['inum' => $r['invoice_number'], 'idt' => $gdate($r['invoice_date']), 'val' => $r2($r['total_amount']), 'pos' => $pos($r['place_of_supply_code']),
        'rchrg' => (int) $r['reverse_charge'] ? 'Y' : 'N', 'inv_typ' => 'R', 'itms' => []];
    $inv['itms'][] = ['num' => count($inv['itms']) + 1, 'itm_det' => ['txval' => $r2($r['txval']), 'rt' => (float) $r['gst_rate'], 'iamt' => $r2($r['iamt']), 'camt' => $r2($r['camt']), 'samt' => $r2($r['samt']), 'csamt' => 0]];
    unset($inv);
    $sheetB2b[] = ['GSTIN/UIN of Recipient' => $ctin, 'Receiver Name' => $r['customer_name'], 'Invoice Number' => $r['invoice_number'], 'Invoice date' => date('d-M-Y', strtotime($r['invoice_date'])),
        'Invoice Value' => $r2($r['total_amount']), 'Place Of Supply' => $pos($r['place_of_supply_code']) . '-' . state_name($pos($r['place_of_supply_code'])),
        'Reverse Charge' => (int) $r['reverse_charge'] ? 'Y' : 'N', 'Applicable % of Tax Rate' => '', 'Invoice Type' => 'Regular B2B', 'E-Commerce GSTIN' => '',
        'Rate' => (float) $r['gst_rate'], 'Taxable Value' => $r2($r['txval']), 'Cess Amount' => 0];
}
$jsonB2b = [];
foreach ($b2b as $ctin => $invs) {
    $jsonB2b[] = ['ctin' => $ctin, 'inv' => array_values($invs)];
}

// B2C Large: inter-state, no GSTIN, bill value above the limit.
$b2clRows = db_all(
    "SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.place_of_supply_code, i.total_amount, ii.gst_rate,
            SUM(ii.taxable_value) AS txval, SUM(ii.igst_amount) AS iamt
     FROM invoices i JOIN invoice_items ii ON ii.invoice_id = i.invoice_id
     WHERE $base AND (i.customer_gstin IS NULL OR i.customer_gstin = '') AND i.is_interstate = 1 AND i.total_amount > ?
     GROUP BY i.invoice_id, ii.gst_rate ORDER BY i.invoice_date, i.invoice_id",
    array_merge($params, [$b2clLimit])
);
$b2cl = [];
$sheetB2cl = [];
$b2clIds = [];
foreach ($b2clRows as $r) {
    $b2clIds[(int) $r['invoice_id']] = true;
    $p = $pos($r['place_of_supply_code']);
    $inv = &$b2cl[$p][$r['invoice_id']];
    $inv ??= ['inum' => $r['invoice_number'], 'idt' => $gdate($r['invoice_date']), 'val' => $r2($r['total_amount']), 'itms' => []];
    $inv['itms'][] = ['num' => count($inv['itms']) + 1, 'itm_det' => ['txval' => $r2($r['txval']), 'rt' => (float) $r['gst_rate'], 'iamt' => $r2($r['iamt']), 'csamt' => 0]];
    unset($inv);
    $sheetB2cl[] = ['Invoice Number' => $r['invoice_number'], 'Invoice date' => date('d-M-Y', strtotime($r['invoice_date'])), 'Invoice Value' => $r2($r['total_amount']),
        'Place Of Supply' => $p . '-' . state_name($p), 'Applicable % of Tax Rate' => '', 'Rate' => (float) $r['gst_rate'], 'Taxable Value' => $r2($r['txval']), 'Cess Amount' => 0, 'E-Commerce GSTIN' => ''];
}
$jsonB2cl = [];
foreach ($b2cl as $p => $invs) {
    $jsonB2cl[] = ['pos' => $p, 'inv' => array_values($invs)];
}

// B2C Small: everything else to consumers, summed by place of supply and rate.
$notLarge = $b2clIds ? ' AND i.invoice_id NOT IN (' . implode(',', array_keys($b2clIds)) . ')' : '';
$b2csRows = db_all(
    "SELECT i.is_interstate, i.place_of_supply_code, ii.gst_rate, SUM(ii.taxable_value) AS txval, SUM(ii.igst_amount) AS iamt,
            SUM(ii.cgst_amount) AS camt, SUM(ii.sgst_amount) AS samt
     FROM invoices i JOIN invoice_items ii ON ii.invoice_id = i.invoice_id
     WHERE $base AND (i.customer_gstin IS NULL OR i.customer_gstin = '')$notLarge
     GROUP BY i.is_interstate, i.place_of_supply_code, ii.gst_rate ORDER BY i.place_of_supply_code, ii.gst_rate",
    $params
);
$jsonB2cs = [];
$sheetB2cs = [];
foreach ($b2csRows as $r) {
    $p = $pos($r['place_of_supply_code']);
    $inter = (int) $r['is_interstate'] === 1;
    $row = ['sply_ty' => $inter ? 'INTER' : 'INTRA', 'pos' => $p, 'typ' => 'OE', 'rt' => (float) $r['gst_rate'], 'txval' => $r2($r['txval'])];
    $row += $inter ? ['iamt' => $r2($r['iamt'])] : ['camt' => $r2($r['camt']), 'samt' => $r2($r['samt'])];
    $jsonB2cs[] = $row + ['csamt' => 0];
    $sheetB2cs[] = ['Type' => 'OE', 'Place Of Supply' => $p . '-' . state_name($p), 'Applicable % of Tax Rate' => '', 'Rate' => (float) $r['gst_rate'],
        'Taxable Value' => $r2($r['txval']), 'Cess Amount' => 0, 'E-Commerce GSTIN' => ''];
}

// HSN summary, separately for B2B and B2C bills.
$hsn = function (bool $b2b) use ($base, $params, $r2) {
    $cond = $b2b ? "i.customer_gstin IS NOT NULL AND i.customer_gstin <> ''" : "(i.customer_gstin IS NULL OR i.customer_gstin = '')";
    $rows = db_all(
        "SELECT COALESCE(NULLIF(ii.hsn_code, ''), '') AS hsn, MAX(ii.product_name) AS descr, ii.unit, ii.gst_rate, SUM(ii.quantity) AS qty, SUM(ii.taxable_value) AS txval,
                SUM(ii.igst_amount) AS iamt, SUM(ii.cgst_amount) AS camt, SUM(ii.sgst_amount) AS samt, SUM(ii.line_total) AS val
         FROM invoices i JOIN invoice_items ii ON ii.invoice_id = i.invoice_id
         WHERE $base AND $cond GROUP BY hsn, ii.unit, ii.gst_rate ORDER BY hsn, ii.gst_rate",
        $params
    );
    $json = [];
    $sheet = [];
    $missing = 0;
    $merged = [];
    foreach ($rows as $r) {
        $key = $r['hsn'] . '|' . uqc($r['unit']) . '|' . $r['gst_rate'];
        $m = &$merged[$key];
        $m ??= ['hsn' => $r['hsn'], 'descr' => $r['descr'], 'uqc' => uqc($r['unit']), 'rt' => (float) $r['gst_rate'], 'qty' => 0, 'txval' => 0, 'iamt' => 0, 'camt' => 0, 'samt' => 0, 'val' => 0];
        foreach (['qty', 'txval', 'iamt', 'camt', 'samt', 'val'] as $f) {
            $m[$f] += (float) $r[$f];
        }
        unset($m);
    }
    foreach (array_values($merged) as $n => $m) {
        if ($m['hsn'] === '') {
            $missing++;
        }
        $json[] = ['num' => $n + 1, 'hsn_sc' => $m['hsn'], 'desc' => mb_substr((string) $m['descr'], 0, 30), 'uqc' => $m['uqc'], 'qty' => round($m['qty'], 2),
            'rt' => $m['rt'], 'txval' => $r2($m['txval']), 'iamt' => $r2($m['iamt']), 'camt' => $r2($m['camt']), 'samt' => $r2($m['samt']), 'csamt' => 0];
        $sheet[] = ['HSN' => $m['hsn'], 'Description' => $m['descr'], 'UQC' => $m['uqc'], 'Total Quantity' => round($m['qty'], 2), 'Total Value' => $r2($m['val']),
            'Rate' => $m['rt'], 'Taxable Value' => $r2($m['txval']), 'Integrated Tax Amount' => $r2($m['iamt']), 'Central Tax Amount' => $r2($m['camt']),
            'State/UT Tax Amount' => $r2($m['samt']), 'Cess Amount' => 0];
    }
    return [$json, $sheet, $missing];
};
[$hsnB2b, $sheetHsnB2b, $missB2b] = $hsn(true);
[$hsnB2c, $sheetHsnB2c, $missB2c] = $hsn(false);

// Documents issued: each bill series with first / last number, total and cancelled.
$docs = [];
$sheetDocs = [];
foreach (db_all(
    "SELECT i.invoice_type, MIN(i.invoice_id) AS first_id, MAX(i.invoice_id) AS last_id, COUNT(*) AS total, SUM(i.status = 'Cancelled') AS cancelled
     FROM invoices i WHERE i.store_id = ? AND i.invoice_date BETWEEN ? AND ? GROUP BY i.invoice_type",
    $params
) as $n => $r) {
    $first = db_value('SELECT invoice_number FROM invoices WHERE invoice_id = ?', [$r['first_id']]);
    $last = db_value('SELECT invoice_number FROM invoices WHERE invoice_id = ?', [$r['last_id']]);
    $docs[] = ['num' => $n + 1, 'from' => $first, 'to' => $last, 'totnum' => (int) $r['total'], 'cancel' => (int) $r['cancelled'], 'net_issue' => (int) $r['total'] - (int) $r['cancelled']];
    $sheetDocs[] = ['Nature of Document' => 'Invoices for outward supply', 'Sr. No. From' => $first, 'Sr. No. To' => $last, 'Total Number' => (int) $r['total'], 'Cancelled' => (int) $r['cancelled']];
}

$json = ['gstin' => (string) $store['gstin'], 'fp' => $fp, 'version' => 'GST3.2', 'hash' => 'hash'];
if ($jsonB2b) {
    $json['b2b'] = $jsonB2b;
}
if ($jsonB2cl) {
    $json['b2cl'] = $jsonB2cl;
}
if ($jsonB2cs) {
    $json['b2cs'] = $jsonB2cs;
}
if ($hsnB2b || $hsnB2c) {
    $json['hsn'] = array_filter(['hsn_b2b' => $hsnB2b, 'hsn_b2c' => $hsnB2c]);
}
if ($docs) {
    $json['doc_issue'] = ['doc_det' => [['doc_num' => 1, 'docs' => $docs]]];
}

$sum = fn(array $rows, string $k) => $r2(array_sum(array_column($rows, $k)));
$warnings = [];
if (!$store['gstin'] || !valid_gstin($store['gstin'])) {
    $warnings[] = 'Add your GSTIN in Settings (Business details) — it is needed in the GSTR-1 file.';
}
if ($missB2b + $missB2c) {
    $warnings[] = ($missB2b + $missB2c) . ' HSN line(s) have no HSN code. Add HSN codes to those products (Products screen) before filing.';
}
json_out(['status' => 'success', 'month' => $month, 'range' => ['from' => $from, 'to' => $to], 'store' => ['name' => $store['name'], 'gstin' => $store['gstin']],
    'warnings' => $warnings,
    'summary' => [
        'b2b_invoices' => count($b2bRows) ? count(array_unique(array_column($b2bRows, 'invoice_id'))) : 0,
        'b2b_taxable' => $sum($b2bRows, 'txval'),
        'b2cl_invoices' => count($b2clIds),
        'b2cs_taxable' => $sum($b2csRows, 'txval'),
        'taxable' => $r2($sum($b2bRows, 'txval') + $sum($b2clRows, 'txval') + $sum($b2csRows, 'txval')),
        'igst' => $r2($sum($b2bRows, 'iamt') + $sum($b2clRows, 'iamt') + $sum($b2csRows, 'iamt')),
        'cgst' => $r2($sum($b2bRows, 'camt') + $sum($b2csRows, 'camt')),
        'sgst' => $r2($sum($b2bRows, 'samt') + $sum($b2csRows, 'samt')),
        'documents' => array_sum(array_column($docs, 'totnum')),
        'cancelled' => array_sum(array_column($docs, 'cancel')),
    ],
    'json' => $json,
    'sheets' => ['b2b' => $sheetB2b, 'b2cl' => $sheetB2cl, 'b2cs' => $sheetB2cs, 'hsn(b2b)' => $sheetHsnB2b, 'hsn(b2c)' => $sheetHsnB2c, 'docs' => $sheetDocs],
]);
