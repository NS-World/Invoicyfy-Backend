<?php
/**
 * Data & Archive test: year archives on Google Drive (mock), purge of old years, frozen report summaries, logs purge.
 * Run after tests/api_v4_test.php on the same throwaway database (it needs bills, suppliers...):
 *   DB_NAME=invoicyfy_v4 php tests/api_archive_test.php http://127.0.0.1:8085 admin@test.com Admin@1234 http://127.0.0.1:8091
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
[$_, $base, $email, $password, $mock] = $argv + [null, 'http://127.0.0.1:8085', 'admin@test.com', 'Admin@1234', 'http://127.0.0.1:8091'];
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/datajobs.php';
set_exception_handler(null);

$token = null;
function call(string $method, string $path, $body = null, ?string $tok = null, bool $raw = false)
{
    global $base, $token;
    $tok ??= $token;
    $url = str_starts_with($path, 'http') ? $path : $base . $path;
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    if ($tok) {
        $h[] = "Authorization: Bearer $tok";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 120]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, $raw ? $out : json_decode((string) $out, true)];
}
$ok = 0;
$bad = 0;
function check(string $name, bool $cond, $info = null): void
{
    global $ok, $bad;
    if ($cond) {
        $ok++;
        echo "  ✓ $name\n";
    } else {
        $bad++;
        echo "  ✗ $name\n      " . substr(json_encode($info, JSON_UNESCAPED_UNICODE), 0, 600) . "\n";
    }
}
function until_done(int $max = 60): array
{
    for ($i = 0; $i < $max; $i++) {
        [, $r] = call('POST', '/master/archive/step.php', []);
        if (empty($r['data']['open_jobs'])) {
            return $r['data'];
        }
    }
    return $r['data'] ?? [];
}

[, $r] = call('POST', '/auth/signin.php', ['email' => $email, 'password' => $password]);
$token = $r['access_token'] ?? die("login failed\n");

echo "Old years of data\n";
// Move some of the test bills into FY 2019-20 / 2020-21 (as if the shop had used the app for years).
$ids = array_column(db_all("SELECT invoice_id FROM invoices WHERE status = 'Paid' ORDER BY invoice_id LIMIT 6"), 'invoice_id');
check('test bills available', count($ids) >= 4, $ids);
[$a, $b2, $c2, $d2] = $ids;
foreach ([[$a, '2019-06-10'], [$b2, '2019-11-20'], [$c2, '2020-05-05'], [$d2, '2020-12-01']] as [$id, $day]) {
    db_query('UPDATE invoices SET invoice_date = ?, created_at = ? WHERE invoice_id = ?', [$day, "$day 12:00:00", $id]);
    db_query('UPDATE payments SET payment_date = ? WHERE invoice_id = ?', [$day, $id]);
}
// An unpaid bill in 2019 (udhaar) must survive the purge.
$unpaid = (int) db_value("SELECT invoice_id FROM invoices WHERE status IN ('Unpaid','Partial') ORDER BY invoice_id LIMIT 1");
if (!$unpaid) {
    $unpaid = (int) $ids[4];
    db_query("UPDATE invoices SET status = 'Unpaid', amount_paid = 0, balance_due = total_amount WHERE invoice_id = ?", [$unpaid]);
    db_query('DELETE FROM payments WHERE invoice_id = ?', [$unpaid]);
}
db_query("UPDATE invoices SET invoice_date = '2019-08-15' WHERE invoice_id = ?", [$unpaid]);
db_query("UPDATE expenses SET expense_date = '2019-07-01' ORDER BY expense_id LIMIT 1");
db_query("UPDATE stock_movements SET created_at = '2019-07-01 10:00:00' ORDER BY movement_id LIMIT 3");
db_query("UPDATE activity_log SET created_at = '2019-01-01 10:00:00' ORDER BY log_id LIMIT 5");
// A supplier bill in 2019, fully paid: the supplier's balance must not change when it is purged.
$sup = db_one('SELECT supplier_id FROM suppliers ORDER BY supplier_id LIMIT 1');
if ($sup) {
    db_query("UPDATE purchases SET purchase_date = '2019-09-09' WHERE supplier_id = ? AND balance_due <= 0 ORDER BY purchase_id LIMIT 1", [$sup['supplier_id']]);
    db_query("UPDATE supplier_payments SET payment_date = '2019-09-09' WHERE supplier_id = ? ORDER BY payment_id LIMIT 1", [$sup['supplier_id']]);
}
stats_rebuild();
$owed = fn() => $sup ? (float) db_value('SELECT (SELECT opening_balance FROM suppliers WHERE supplier_id = ?) + (SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE supplier_id = ? AND status <> \'Cancelled\')
    - (SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id = ?)', [$sup['supplier_id'], $sup['supplier_id'], $sup['supplier_id']]) : 0.0;
$owedBefore = $owed();
[, $sales19] = call('GET', '/reports/sales.php?group=month&from=2019-04-01&to=2020-03-31');
[, $dash] = call('GET', '/reports/dashboard.php');
$dueBefore = $dash['kpis']['outstanding'] ?? $dash['outstanding']['total'] ?? null;
[, $allBefore] = call('GET', '/master/invoice/invoices.php?page=1&limit=5');

echo "Data & Archive screen\n";
[$c, $r] = call('GET', '/master/archive/overview.php');
$years = array_column($r['data']['years'] ?? [], null, 'fy');
check('overview lists FY 2019-20, 2020-21 and the running year', $c === 200 && isset($years[2019], $years[2020], $years[fy_current()]), array_keys($years));
check('years show bills from the day summaries', ($years[2019]['bills'] ?? 0) >= 3, $years[2019] ?? null);
check('purge is off until a period is chosen', ($years[2019]['purge_blocker'] ?? '') !== null && str_contains((string) $years[2019]['purge_blocker'], 'off'), $years[2019] ?? null);
[$c] = call('POST', '/master/archive/update-settings.php', ['keep_years' => 2]);
check('keeping less than 3 years is refused', $c === 400);
[$c, $r] = call('POST', '/master/archive/update-settings.php', ['keep_years' => 3, 'logs_keep_months' => 6]);
check('keep 3 years saved', $c === 200 && $r['data']['settings']['keep_years'] === 3 && $r['data']['purge_before'] === (fy_current() - 3) . '-04-01', $r['data']['settings'] ?? $r);
[$c, $r] = call('GET', '/master/archive/purge-preview.php?fy=2019');
check('preview counts bills to remove and the unpaid bill kept', $c === 200 && $r['data']['remove']['invoices'] >= 2 && $r['data']['kept']['unpaid_bills'] >= 1, $r);
check('preview says the archive must be stored first', str_contains((string) ($r['data']['blocker'] ?? ''), 'archive'), $r['data']['blocker'] ?? null);
[$c, $r] = call('POST', '/master/archive/start-purge.php', ['fy' => 2019, 'confirm' => 'PURGE']);
check('purge refused without a safe archive (409)', $c === 409, $r);
[$c, $r] = call('POST', '/master/archive/start-purge.php', ['fy' => 2019]);
check('purge needs the word PURGE', $c === 400, $r);
[$c] = call('POST', '/master/archive/create-archive.php', ['fy' => fy_current()]);
check('running year cannot be archived', $c === 400);

echo "Year archives\n";
// Connect the mock Google Drive again (the V4 test disconnected it).
call('POST', '/master/backup/update-drive-settings.php', ['enabled' => true, 'time' => '00:00', 'client_id' => '1234567890-abc.apps.googleusercontent.com', 'client_secret' => 'test-secret', 'keep' => 30]);
[, $r] = call('GET', '/master/backup/drive-connect.php?return=' . rawurlencode('http://127.0.0.1:4173/backup'));
parse_str((string) parse_url($r['url'] ?? '', PHP_URL_QUERY), $q);
call('GET', '/master/backup/drive-callback.php?code=good-code&state=' . rawurlencode($q['state'] ?? ''), null, '', true);
[$c, $r] = call('POST', '/master/archive/create-archive.php', ['fy' => 2019]);
check('archive of FY 2019-20 started', $c === 200 && !empty($r['job_id']), $r);
call('POST', '/master/archive/create-archive.php', ['fy' => 2020]);
$d = until_done();
$years = array_column($d['years'] ?? [], null, 'fy');
check('FY 2019-20 archive stored on Drive and checked', ($years[2019]['archive']['on_drive'] ?? false) && ($years[2019]['archive']['safe'] ?? false), $years[2019]['archive'] ?? null);
check('FY 2020-21 archive stored on Drive', $years[2020]['archive']['on_drive'] ?? false, $years[2020]['archive'] ?? null);
[, $ms] = call('GET', "$mock/state", null, '');
$folders = array_column(array_filter($ms['files'], fn($f) => !empty($f['folder'])), null, 'id');
$final = array_values(array_filter($ms['files'], fn($f) => ($f['name'] ?? '') === 'FY-2019-20-FINAL.json.gz' && empty($f['deleted'])));
check('Drive: "FY 2019-20" folder holds FY-2019-20-FINAL.json.gz', $final && ($folders[$final[0]['parents'][0]]['name'] ?? '') === 'FY 2019-20', $final);
[$c, $gz] = call('GET', '/master/archive/download.php?fy=2019', null, null, true);
$json = json_decode((string) @gzdecode((string) $gz), true);
$dates = array_column(array_map(fn($row) => array_combine($json['tables']['invoices']['columns'], $row), $json['tables']['invoices']['rows'] ?? []), 'invoice_date');
check('archive file opens: only FY 2019-20 bills, with their items and all customers', $c === 200 && $dates && min($dates) >= '2019-04-01' && max($dates) < '2020-04-01'
    && count($json['tables']['invoice_items']['rows'] ?? []) >= count($dates) && count($json['tables']['customers']['rows'] ?? []) === (int) db_value('SELECT COUNT(*) FROM customers'), [$c, $dates]);
[$c] = call('GET', '/master/archive/download.php?fy=2018');
check('missing archive -> 404', $c === 404);

echo "Purge\n";
[$c, $r] = call('POST', '/master/archive/start-purge.php', ['fy' => 2020, 'confirm' => 'PURGE']);
check('oldest year first (2020 refused while 2019 has data)', $c === 409 && str_contains($r['message'] ?? '', '2019'), $r);
[$c, $r] = call('POST', '/master/archive/start-purge.php', ['fy' => 2019, 'confirm' => 'purge']);
check('purge of FY 2019-20 started', $c === 200, $r);
$d = until_done();
$job = array_values(array_filter($d['jobs'] ?? [], fn($j) => $j['kind'] === 'purge'))[0] ?? null;
check('purge finished with counts per table', ($job['status'] ?? '') === 'done' && ($job['counts']['invoices'] ?? 0) >= 2 && ($job['counts']['invoice_items'] ?? 0) >= 2, $job);
[$c] = call('GET', "/master/invoice/get-invoice-by-id.php?invoice_id=$a");
check('paid 2019 bill removed', $c === 404);
[$c] = call('GET', "/master/invoice/get-invoice-by-id.php?invoice_id=$unpaid");
check('unpaid 2019 bill kept (udhaar)', $c === 200);
[$c] = call('GET', "/master/invoice/get-invoice-by-id.php?invoice_id=$c2");
check('FY 2020-21 bills untouched', $c === 200);
[, $s2] = call('GET', '/reports/sales.php?group=month&from=2019-04-01&to=2020-03-31');
check('FY 2019-20 report totals unchanged (summaries kept)', abs(($s2['totals']['total_amount'] ?? -1) - ($sales19['totals']['total_amount'] ?? -2)) < 0.01, [$s2['totals'] ?? null, $sales19['totals'] ?? null]);
[, $dash2] = call('GET', '/reports/dashboard.php');
$dueAfter = $dash2['kpis']['outstanding'] ?? $dash2['outstanding']['total'] ?? null;
check('dashboard outstanding (udhaar) unchanged', json_encode($dueAfter) === json_encode($dueBefore), [$dueBefore, $dueAfter]);
[, $allAfter] = call('GET', '/master/invoice/invoices.php?page=1&limit=5');
check('bill list count = before − purged bills', $allAfter['total'] === $allBefore['total'] - $job['counts']['invoices'], [$allBefore['total'], $allAfter['total'], $job['counts']['invoices'] ?? null]);
check('supplier balance unchanged after purging paid supplier bills', abs($owed() - $owedBefore) < 0.01, [$owedBefore, $owed()]);
check('days before 1 Apr 2020 marked purged', retention_settings()['purged_before'] === '2020-04-01', retention_settings());
stats_rebuild();
[, $s3] = call('GET', '/reports/sales.php?group=month&from=2019-04-01&to=2020-03-31');
check('"Update database" rebuild keeps purged years\' totals', abs(($s3['totals']['total_amount'] ?? -1) - ($sales19['totals']['total_amount'] ?? -2)) < 0.01, $s3['totals'] ?? null);
[$c, $r] = call('POST', '/master/archive/start-purge.php', ['fy' => 2019, 'confirm' => 'PURGE']);
check('purging the same year again is refused', $c === 409, $r);
[$c, $r] = call('GET', '/master/archive/overview.php');
$y = array_column($r['data']['years'], null, 'fy');
check('overview shows FY 2019-20 purged, with its archive', !empty($y[2019]['purged_at']) && ($y[2019]['archive']['on_drive'] ?? false), $y[2019] ?? null);

echo "Logs\n";
$logsBefore = (int) db_value('SELECT COUNT(*) FROM activity_log');
[$c, $r] = call('POST', '/master/archive/purge-logs.php', ['confirm' => 'PURGE']);
$d = until_done();
$job = array_values(array_filter($d['jobs'] ?? [], fn($j) => $j['kind'] === 'logs'))[0] ?? null;
check('old activity log entries removed (older than 6 months)', ($job['status'] ?? '') === 'done' && ($job['counts']['activity_log'] ?? 0) >= 5 && (int) db_value('SELECT COUNT(*) FROM activity_log WHERE created_at < ?', [logs_cutoff()]) === 0, $job);

echo "Jobs\n";
db_query("UPDATE fy_archives SET drive_verified_at = NULL WHERE fy = 2020");
$jid = data_job_queue('archive', 2020, null);
[$c, $r] = call('POST', '/master/archive/cancel-job.php', ['job_id' => $jid]);
check('a waiting job can be stopped', $c === 200 && data_job($jid)['status'] === 'cancelled', $r);
[$c] = call('POST', '/master/archive/cancel-job.php', ['job_id' => $jid]);
check('stopping it again -> 404', $c === 404);
[, $r] = call('POST', '/auth/signin.php', ['email' => 'cashier4@test.com', 'password' => 'Cashier@123']);
$staff = $r['access_token'] ?? null;
if ($staff) {
    [$c] = call('GET', '/master/archive/overview.php', null, $staff);
    check('cashier cannot open Data & Archive (403)', $c === 403);
}
$t = microtime(true);
call('GET', '/master/archive/overview.php');
check('overview answers fast', (microtime(true) - $t) * 1000 < 500, round((microtime(true) - $t) * 1000) . ' ms');

echo "\n" . ($ok + $bad) . " checks, $bad failed\n";
exit($bad ? 1 : 0);
