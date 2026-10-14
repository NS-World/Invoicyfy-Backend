<?php
/**
 * Public bill page for the link sent on WhatsApp / SMS / Email: GET ?i=<invoice_id>&s=<signature>.
 * No login: the HMAC signature (from the server secret) makes the link impossible to guess or change.
 * Shows only that bill; "Save as PDF" uses the phone's print dialog.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/channels.php';
allow_methods('GET');

$id = (int) ($_GET['i'] ?? 0);
$sig = (string) ($_GET['s'] ?? '');
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data: https:; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
header('X-Robots-Tag: noindex, nofollow');

function page(string $title, string $body): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . htmlspecialchars($title) . '</title><style>'
        . 'body{margin:0;background:#f1f5f9;font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#111827}'
        . '.b{max-width:440px;margin:16px auto;background:#fff;border-radius:14px;box-shadow:0 6px 24px rgba(0,0,0,.08);padding:20px}'
        . 'h1{font-size:19px;margin:0 0 2px;text-align:center}.c{text-align:center;color:#4b5563;font-size:12.5px}'
        . 'table{width:100%;border-collapse:collapse;margin-top:12px}td,th{padding:6px 2px;text-align:left;vertical-align:top}'
        . 'th{font-size:12px;color:#6b7280;border-bottom:1px solid #e5e7eb}.r{text-align:right}.t td{border-top:1px dashed #d1d5db}'
        . '.g{font-size:18px;font-weight:800}.m{display:flex;justify-content:space-between;margin-top:10px;font-size:12.5px;color:#4b5563}'
        . 'button{display:block;width:100%;margin-top:16px;padding:12px;border:0;border-radius:10px;background:#0b7285;color:#fff;font-size:15px;font-weight:700}'
        . '@media print{body{background:#fff}.b{box-shadow:none;margin:0}button{display:none}}</style></head><body><div class="b">'
        . $body . '</div></body></html>';
    exit;
}

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$num = fn($v) => number_format((float) $v, 2);
if ($id <= 0 || !hash_equals(bill_signature($id), $sig)) {
    http_response_code(404);
    page('Bill not found', '<h1>Bill not found</h1><p class="c">This link is not valid. Please ask the shop for your bill.</p>');
}
$inv = load_invoice($id);
if (!$inv) {
    http_response_code(404);
    page('Bill not found', '<h1>Bill not found</h1>');
}
$s = $inv['store'];
$rows = '';
foreach ($inv['items'] as $it) {
    $rows .= '<tr><td>' . $e($it['product_name']) . '<div class="c" style="text-align:left">' . $e(rtrim(rtrim(number_format($it['quantity'], 3, '.', ''), '0'), '.') . ' ' . ($it['unit'] ?? '')) . ' × ₹' . $num($it['unit_price']) . '</div></td><td class="r">₹' . $num($it['line_total']) . '</td></tr>';
}
$tax = '';
if ((float) $inv['tax_amount'] > 0) {
    $tax = (float) $inv['igst_amount'] > 0
        ? '<tr><td>IGST</td><td class="r">₹' . $num($inv['igst_amount']) . '</td></tr>'
        : '<tr><td>CGST</td><td class="r">₹' . $num($inv['cgst_amount']) . '</td></tr><tr><td>SGST</td><td class="r">₹' . $num($inv['sgst_amount']) . '</td></tr>';
}
$disc = (float) $inv['discount_amount'] > 0 ? '<tr><td>Discount</td><td class="r">−₹' . $num($inv['discount_amount']) . '</td></tr>' : '';
$status = $inv['status'] === 'Cancelled' ? '<p class="c" style="color:#b91c1c;font-weight:700">This bill was cancelled</p>' : '';
$paid = (float) $inv['balance_due'] > 0 ? '<tr><td>Balance due</td><td class="r"><b>₹' . $num($inv['balance_due']) . '</b></td></tr>' : '<tr><td colspan="2" class="c">Paid · Thank you!</td></tr>';
page(
    "Bill {$inv['invoice_number']} - {$s['name']}",
    '<h1>' . $e($s['name']) . '</h1>'
    . '<div class="c">' . $e(trim(implode(', ', array_filter([$s['address'], $s['city']])))) . '</div>'
    . ($s['gstin'] ? '<div class="c">GSTIN ' . $e($s['gstin']) . '</div>' : '')
    . ($s['phone'] ? '<div class="c">📞 ' . $e($s['phone']) . '</div>' : '')
    . $status
    . '<div class="m"><span>Bill <b>' . $e($inv['invoice_number']) . '</b></span><span>' . $e(date('d M Y', strtotime($inv['invoice_date']))) . '</span></div>'
    . ($inv['customer_name'] ? '<div class="m"><span>' . $e($inv['customer_name']) . '</span></div>' : '')
    . '<table><tr><th>Item</th><th class="r">Amount</th></tr>' . $rows . '</table>'
    . '<table class="t"><tr><td>Taxable value</td><td class="r">₹' . $num($inv['taxable_amount']) . '</td></tr>' . $disc . $tax
    . ((float) $inv['round_off'] != 0 ? '<tr><td>Round off</td><td class="r">₹' . $num($inv['round_off']) . '</td></tr>' : '')
    . '<tr><td class="g">Total</td><td class="r g">₹' . $num($inv['total_amount']) . '</td></tr>' . $paid . '</table>'
    . ($s['receipt_footer'] ? '<p class="c">' . $e($s['receipt_footer']) . '</p>' : '')
    . '<button onclick="window.print()">Save as PDF / Print</button>'
);
