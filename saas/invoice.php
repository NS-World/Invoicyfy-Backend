<?php
/** GET ?no=&s= — Khanak's GST invoice for a plan payment (printable page; the link is signed). */
require_once __DIR__ . '/_common.php';
allow_methods('GET');
$no = (string) ($_GET['no'] ?? '');
if ($no === '' || !hash_equals(saas_invoice_signature($no), (string) ($_GET['s'] ?? ''))) {
    json_out(['status' => 'error', 'message' => 'Invoice not found'], 404);
}
$inv = saas_q('SELECT * FROM saas_invoices WHERE invoice_no = ?', [$no])->fetch();
if (!$inv) {
    json_out(['status' => 'error', 'message' => 'Invoice not found'], 404);
}
$d = json_decode((string) $inv['details'], true) ?: [];
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$inter = ($d['seller']['state_code'] ?? '24') !== substr((string) ($d['buyer']['gstin'] ?? ''), 0, 2) && !empty($d['buyer']['gstin']);
$rate = (float) ($d['gst_rate'] ?? 18);
$taxRows = $inter
    ? '<tr><td colspan="2">IGST ' . $rate . '%</td><td class="r">₹' . number_format((int) $inv['gst'], 2) . '</td></tr>'
    : '<tr><td colspan="2">CGST ' . ($rate / 2) . '%</td><td class="r">₹' . number_format($inv['gst'] / 2, 2) . '</td></tr><tr><td colspan="2">SGST ' . ($rate / 2) . '%</td><td class="r">₹' . number_format($inv['gst'] / 2, 2) . '</td></tr>';
header('Content-Type: text/html; charset=utf-8');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; script-src \'unsafe-inline\'');
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Invoice ' . $h($no) . '</title>'
    . '<style>body{font-family:Arial,sans-serif;color:#142026;max-width:760px;margin:24px auto;padding:0 16px}h1{color:#0b4f5c;margin:0}table{width:100%;border-collapse:collapse;margin-top:16px}td,th{padding:8px;border-bottom:1px solid #dde4e2;text-align:left}.r{text-align:right}.muted{color:#5a6b70;font-size:13px}.grid{display:flex;gap:24px;flex-wrap:wrap;margin-top:16px}.grid>div{flex:1;min-width:220px}@media print{button{display:none}}</style></head><body>'
    . '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap"><div><h1>khanak</h1><div class="muted">' . $h($d['seller']['name'] ?? 'Khanak') . '<br>' . nl2br($h($d['seller']['address'] ?? '')) . ($d['seller']['gstin'] ? '<br>GSTIN ' . $h($d['seller']['gstin']) : '') . '</div></div>'
    . '<div class="r"><b>TAX INVOICE</b><br>' . $h($no) . '<br><span class="muted">' . $h(date('d M Y', strtotime($inv['created_at']))) . '</span></div></div>'
    . '<div class="grid"><div><b>Billed to</b><br>' . $h($d['buyer']['name'] ?? '') . '<br>' . $h($d['buyer']['owner'] ?? '') . '<br>' . $h($d['buyer']['city'] ?? '') . ($d['buyer']['gstin'] ? '<br>GSTIN ' . $h($d['buyer']['gstin']) : '') . '</div>'
    . '<div><b>Shop code</b><br>' . $h($d['buyer']['shop_code'] ?? '') . '<br><span class="muted">Payment ' . $h($d['payment_id'] ?? '') . '</span></div></div>'
    . '<table><tr><th>Item</th><th>SAC</th><th class="r">Amount</th></tr><tr><td>' . $h($d['item'] ?? '') . '</td><td>' . $h($d['sac'] ?? '') . '</td><td class="r">₹' . number_format((int) $inv['amount'], 2) . '</td></tr>'
    . $taxRows . '<tr><th>Total</th><th></th><th class="r">₹' . number_format((int) $inv['total'], 2) . '</th></tr></table>'
    . '<p class="muted">Paid online. This is a computer generated invoice.</p><button onclick="print()">Print / Save as PDF</button></body></html>';
