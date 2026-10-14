<?php
/** Lookup lists used by the front-end forms (one request instead of many). */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../expense/_common.php';
allow_methods('GET');
require_auth();

$states = [];
foreach (GST_STATES as $code => $name) {
    $states[] = ["code" => (string) $code, "name" => $name];
}
json_out([
    "status" => "success",
    "data" => [
        "gst_rates" => GST_RATES,
        "states" => $states,
        "payment_methods" => array_values(array_diff(PAYMENT_METHODS, ['Online Platform'])), // set only by online platform bills
        "order_types" => ORDER_TYPES,
        "order_statuses" => ORDER_STATUSES,
        "units" => ['pcs', 'nos', 'kg', 'g', 'ltr', 'ml', 'box', 'pack', 'dozen', 'mtr', 'sqft', 'set', 'pair', 'hrs', 'service'],
        "expense_categories" => DEFAULT_EXPENSE_CATEGORIES,
    ],
]);
