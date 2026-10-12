<?php
function store_payload(array $d): array
{
    $name = str_field($d, 'name');
    if (!$name) {
        fail('Business / store name is required');
    }
    $gstin = normalize_gstin(str_field($d, 'gstin'));
    $stateCode = resolve_state_code(str_field($d, 'state_code'), $gstin, str_field($d, 'state'));
    $email = str_field($d, 'email');
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Enter a valid email address');
    }
    $pan = str_field($d, 'pan');
    if ($pan) {
        $pan = strtoupper($pan);
        if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
            fail('Invalid PAN format');
        }
    }
    $ifsc = str_field($d, 'bank_ifsc');
    if ($ifsc) {
        $ifsc = strtoupper($ifsc);
        if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
            fail('Invalid IFSC code');
        }
    }
    $prefix = str_field($d, 'invoice_prefix', 'INV');
    if (!preg_match('/^[A-Za-z0-9\-]{1,6}$/', $prefix)) {
        fail('Invoice prefix must be 1-6 letters/numbers');
    }
    $billPrefix = (string) str_field($d, 'bill_prefix', '');
    if ($billPrefix !== '' && !preg_match('/^[A-Za-z0-9\-]{1,6}$/', $billPrefix)) {
        fail('Bill prefix must be up to 6 letters/numbers');
    }
    return [
        'name' => $name,
        'legal_name' => str_field($d, 'legal_name'),
        'tagline' => str_field($d, 'tagline'),
        'address' => str_field($d, 'address'),
        'city' => str_field($d, 'city'),
        'state' => $stateCode ? state_name($stateCode) : str_field($d, 'state'),
        'state_code' => $stateCode,
        'pincode' => str_field($d, 'pincode'),
        'phone' => str_field($d, 'phone'),
        'email' => $email,
        'gstin' => $gstin,
        'pan' => $pan,
        'fssai_no' => str_field($d, 'fssai_no'),
        'logo_url' => str_field($d, 'logo_url'),
        'invoice_prefix' => strtoupper($prefix),
        'bill_prefix' => strtoupper($billPrefix),
        'bank_name' => str_field($d, 'bank_name'),
        'bank_account_name' => str_field($d, 'bank_account_name'),
        'bank_account_no' => str_field($d, 'bank_account_no'),
        'bank_ifsc' => $ifsc,
        'bank_branch' => str_field($d, 'bank_branch'),
        'upi_id' => str_field($d, 'upi_id'),
        'invoice_terms' => str_field($d, 'invoice_terms'),
        'receipt_footer' => str_field($d, 'receipt_footer'),
    ] + (array_key_exists('logo', $d) ? ['logo' => clean_image_data($d['logo'], 500 * 1024, 'Logo')] : []);
}

/** Accepts a small image as a data URI (the app resizes logos before upload). */
function clean_image_data(?string $data, int $maxBytes, string $what): ?string
{
    if ($data === null || $data === '') {
        return null;
    }
    if (!preg_match('#^data:image/(png|jpeg|webp|gif);base64,([A-Za-z0-9+/=]+)$#', $data, $m)) {
        fail("$what must be a PNG, JPG or WEBP image");
    }
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || strlen($bytes) > $maxBytes) {
        fail("$what is too large (max " . round($maxBytes / 1024) . " KB)");
    }
    if (@getimagesizefromstring($bytes) === false) {
        fail("$what is not a valid image");
    }
    return $data;
}

const INVOICE_SETTING_BOOLS = [
    'show_logo', 'show_status', 'show_hsn_column', 'show_discount_column', 'show_hsn_summary', 'show_amount_in_words',
    'show_bank', 'show_upi_qr', 'show_terms', 'show_signature', 'show_customer_phone', 'show_fssai', 'show_item_notes',
    'show_cashier', 'show_payment_mode', 'show_gstin_on_receipt', 'show_tax_on_receipt', 'show_address_on_receipt',
    'receipt_show_price_col', 'receipt_bold_items', 'show_store_name',
];
/** Layout numbers from the designer: [min, max]. */
const INVOICE_SETTING_NUMBERS = [
    'receipt_font_size' => [8, 20], 'receipt_padding' => [0, 10], 'receipt_line_height' => [1, 2.2], 'receipt_store_name_size' => [0.8, 3],
    'receipt_col_qty' => [4, 14], 'receipt_col_price' => [5, 16], 'receipt_col_amount' => [5, 16], 'receipt_logo_width' => [15, 70],
    'a4_font_size' => [8, 15], 'a4_padding' => [0, 20], 'a4_cell_padding' => [1, 10], 'a4_title_size' => [12, 36], 'a4_logo_height' => [20, 120],
];
const INVOICE_BLOCK_POSITIONS = ['top', 'after_header', 'before_items', 'after_items', 'after_totals', 'bottom'];
const INVOICE_SETTING_TEXTS = [
    'a4_template' => 20, 'receipt_template' => 20, 'accent_color' => 7, 'title_tax' => 40, 'title_retail' => 40,
    'header_note' => 200, 'footer_note' => 200, 'signature_label' => 60, 'receipt_header_note' => 120, 'receipt_footer_note' => 200,
    'receipt_separator' => 10,
];

function clean_invoice_settings($raw): array
{
    if (!is_array($raw)) {
        fail('Invalid invoice settings');
    }
    $out = [];
    foreach (INVOICE_SETTING_BOOLS as $k) {
        if (array_key_exists($k, $raw)) {
            $out[$k] = (bool) $raw[$k];
        }
    }
    foreach (INVOICE_SETTING_TEXTS as $k => $max) {
        if (array_key_exists($k, $raw) && $raw[$k] !== null) {
            $out[$k] = mb_substr(trim((string) $raw[$k]), 0, $max);
        }
    }
    if (isset($out['accent_color']) && !preg_match('/^#[0-9a-fA-F]{6}$/', $out['accent_color'])) {
        fail('Accent colour must look like #045864');
    }
    if (isset($out['receipt_separator']) && !in_array($out['receipt_separator'], ['solid', 'dashed', 'dotted', 'double'], true)) {
        $out['receipt_separator'] = 'solid';
    }
    foreach (INVOICE_SETTING_NUMBERS as $k => [$min, $max]) {
        if (isset($raw[$k]) && is_numeric($raw[$k])) {
            $out[$k] = round(min($max, max($min, (float) $raw[$k])), 2);
        }
    }
    // Free text blocks (offers, timings, thank-you lines, emoji...) printed at a chosen place.
    $out['custom_blocks'] = [];
    foreach (array_slice((array) ($raw['custom_blocks'] ?? []), 0, 12) as $b) {
        $text = mb_substr(trim(str_replace("\r", '', (string) ($b['text'] ?? ''))), 0, 500);
        if ($text === '') {
            continue;
        }
        $out['custom_blocks'][] = [
            'text' => $text,
            'position' => in_array($b['position'] ?? '', INVOICE_BLOCK_POSITIONS, true) ? $b['position'] : 'bottom',
            'align' => in_array($b['align'] ?? '', ['left', 'center', 'right'], true) ? $b['align'] : 'center',
            'size' => in_array($b['size'] ?? '', ['small', 'normal', 'large', 'xlarge'], true) ? $b['size'] : 'normal',
            'bold' => !empty($b['bold']),
            'italic' => !empty($b['italic']),
            'boxed' => !empty($b['boxed']),
            'show_on' => in_array($b['show_on'] ?? 'both', ['both', 'a4', 'receipt'], true) ? $b['show_on'] : 'both',
        ];
    }
    $out['qr_codes'] = [];
    foreach (array_slice((array) ($raw['qr_codes'] ?? []), 0, 4) as $i => $q) {
        $label = mb_substr(trim((string) ($q['label'] ?? '')), 0, 40);
        $url = trim((string) ($q['url'] ?? ''));
        $image = clean_image_data($q['image'] ?? null, 300 * 1024, 'QR image ' . ($i + 1));
        if ($url !== '' && !preg_match('#^(https?://|upi://|mailto:|tel:)#i', $url)) {
            fail('QR link ' . ($i + 1) . ' must start with https://, upi://, mailto: or tel:');
        }
        if ($url === '' && !$image) {
            continue;
        }
        $show = in_array($q['show_on'] ?? 'both', ['both', 'a4', 'receipt'], true) ? $q['show_on'] : 'both';
        $out['qr_codes'][] = ['label' => $label, 'url' => mb_substr($url, 0, 500), 'image' => $image, 'show_on' => $show];
    }
    return $out;
}
