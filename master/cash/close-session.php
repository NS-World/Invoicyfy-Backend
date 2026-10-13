<?php
/**
 * Close the day: POST { session_id, counted_cash, denominations: {"500": 3, "100": 7, ...}, notes }.
 * When denominations are sent, counted_cash is their total. The day's figures are frozen in a snapshot (Z report).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/cash.php';
allow_methods('POST');
$user = require_auth();

$d = input();
$denoms = clean_denominations($d['denominations'] ?? null);
$counted = $denoms ? (float) array_sum(array_map(fn($k, $v) => (int) $k * $v, array_keys($denoms), $denoms)) : num_field($d, 'counted_cash');
if ($counted === null || $counted < 0) {
    fail('Enter the cash counted in the drawer');
}
$notes = mb_substr((string) str_field($d, 'notes', ''), 0, 500);

$pdo->beginTransaction();
$s = load_cash_session($user, int_field($d, 'session_id') ?? 0, true);
if ($s['status'] !== 'Open') {
    fail('This day is already closed', 409);
}
$closedAt = date('Y-m-d H:i:s');
$report = cash_session_report($s + ['closed_at' => $closedAt]);
$diff = money($counted - $report['expected_cash']);
if (abs($diff) >= 0.01 && $notes === '' && abs($diff) > 100) {
    fail('Cash is ' . ($diff > 0 ? 'more' : 'less') . ' by ' . rupees(abs($diff)) . '. Write a note explaining the difference.', 422, ['code' => 'note_required', 'difference' => $diff]);
}
$report['counted_cash'] = money($counted);
$report['difference'] = $diff;
$opening = $s['denominations'] ? json_decode($s['denominations'], true) : [];
update_row('cash_sessions', 'session_id', (int) $s['session_id'], [
    'status' => 'Closed',
    'closed_by' => (int) $user['user_id'],
    'closed_at' => $closedAt,
    'expected_cash' => $report['expected_cash'],
    'counted_cash' => money($counted),
    'difference' => $diff,
    'denominations' => json_encode(($opening ?: []) + ['closing' => $denoms]),
    'summary' => json_encode($report, JSON_UNESCAPED_UNICODE),
    'notes' => trim(($s['notes'] ? $s['notes'] . "\n" : '') . $notes) ?: null,
    'open_lock' => null,
]);
$pdo->commit();
log_activity('cash.close', 'cash_session', $s['session_id'],
    'Closed the day: expected ' . rupees($report['expected_cash']) . ', counted ' . rupees($counted) . ($diff != 0 ? ', difference ' . rupees($diff) : ''), ['difference' => $diff], $s['store_id']);
json_out(['status' => 'success', 'message' => 'Day closed', 'data' => cash_session_full(load_cash_session($user, (int) $s['session_id']))]);
