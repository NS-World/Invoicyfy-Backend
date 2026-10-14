<?php
/**
 * Helpers that keep list endpoints fast on large tables (millions of rows):
 *
 *  - search_matchers(): turns the search box text into index-friendly conditions (FULLTEXT word search,
 *    prefix match on numbers / phones, exact GSTIN) instead of slow '%text%' scans.
 *  - search_join(): combines those conditions into one derived table of matching ids.
 *  - page_ids() + rows_in_order(): "deferred join" pagination — first the ids of one page are read from a
 *    small index, then only those rows are loaded, so page 5,000 is as quick as page 1.
 */

/** Words for a FULLTEXT boolean search ("ravi pat" => "+ravi* +pat*"), or null when no word is long enough. */
function fulltext_terms(string $search): ?string
{
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY);
    $terms = [];
    foreach ($words as $w) {
        // InnoDB indexes words of 3+ characters by default; shorter ones cannot be searched with MATCH.
        if (mb_strlen($w) >= 3) {
            $terms[] = '+' . $w . '*';
        }
    }
    return $terms ? implode(' ', array_slice($terms, 0, 8)) : null;
}

/** Escapes % and _ so user text is matched literally inside LIKE. */
function like_prefix(string $value): string
{
    return addcslashes($value, '%_\\') . '%';
}

function looks_like_gstin(string $s): bool
{
    return (bool) preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', strtoupper($s));
}

/**
 * Index-friendly conditions for one table. $spec:
 *   'text'   => FULLTEXT-indexed columns, e.g. 'customer_name' or 'name, company_name'
 *   'prefix' => columns matched with "starts with" (need a normal index), e.g. ['invoice_number']
 *   'digits' => columns matched with "starts with" only when the search is mostly digits (phones)
 *   'exact'  => columns matched exactly (e.g. GSTIN, SKU)
 *   'gstin'  => column holding a GSTIN (matched exactly when the text looks like one)
 *   'short'  => column used for a "starts with" match when every word is shorter than 3 letters
 * Returns a list of [sql, params] conditions; any of them matching means the row matches.
 */
function search_matchers(string $search, array $spec, string $alias = ''): array
{
    $q = function (string $cols) use ($alias): string {
        return $alias === '' ? $cols : implode(', ', array_map(fn($c) => "$alias." . trim($c), explode(',', $cols)));
    };
    foreach (['prefix', 'digits', 'suffix', 'exact'] as $k) {
        $spec[$k] = array_map($q, $spec[$k] ?? []);
    }
    foreach (['gstin', 'text', 'short'] as $k) {
        if (!empty($spec[$k])) {
            $spec[$k] = $q($spec[$k]);
        }
    }
    $search = trim(mb_substr($search, 0, 100));
    if ($search === '') {
        return [];
    }
    $out = [];
    $digits = preg_replace('/\D+/', '', $search);
    $mostlyDigits = $digits !== '' && strlen($digits) >= max(1, (int) floor(mb_strlen(preg_replace('/\s+/', '', $search)) * 0.6));
    foreach ($spec['prefix'] ?? [] as $col) {
        $out[] = ["$col LIKE ?", [like_prefix($search)]];
    }
    if ($mostlyDigits && strlen($digits) >= 3) {
        foreach ($spec['digits'] ?? [] as $col) {
            $out[] = ["$col LIKE ?", [like_prefix($digits)]];
        }
    }
    // "ends with" only for text with a digit in it (an order number never ends in letters only).
    foreach (preg_match('/\d/', $search) ? $spec['suffix'] ?? [] : [] as $col) {
        // "ends with" (e.g. order number ORD/26-27/0035 found by typing 35): reads only that column's index.
        $out[] = ["$col LIKE ?", ['%' . addcslashes($search, '%_\\')]];
    }
    foreach ($spec['exact'] ?? [] as $col) {
        $out[] = ["$col = ?", [$search]];
    }
    if (!empty($spec['gstin']) && looks_like_gstin($search)) {
        $out[] = ["{$spec['gstin']} = ?", [strtoupper($search)]];
    }
    if (!empty($spec['text']) && !$mostlyDigits) {
        $ft = fulltext_terms($search);
        if ($ft !== null) {
            $out[] = ["MATCH({$spec['text']}) AGAINST (? IN BOOLEAN MODE)", [$ft]];
        } elseif (!empty($spec['short'])) {
            $out[] = ["{$spec['short']} LIKE ?", [like_prefix($search)]];
        }
    }
    return $out;
}

/**
 * Joins the matching ids as a derived table: "JOIN (SELECT pk FROM t WHERE c1 UNION SELECT pk FROM t WHERE c2) m".
 * Each part uses its own index; MySQL/MariaDB build the small id list once. Returns [joinSql, params] or ['', []].
 */
function search_join(string $search, string $table, string $pk, string $alias, array $spec): array
{
    $selects = [];
    foreach (search_matchers($search, $spec) as [$sql, $p]) {
        $selects[] = ["SELECT $pk AS id FROM $table WHERE $sql", $p];
    }
    // 'via' => [parent table, parent pk, child fk, parent spec]: also match through the parent, e.g. payments by
    // the bill's customer name. Driven by the parent's index: matching parent rows joined to their children.
    if (!empty($spec['via'])) {
        [$vTable, $vPk, $fk, $vSpec] = $spec['via'];
        foreach (search_matchers($search, $vSpec, 'v') as [$sql, $p]) {
            $selects[] = ["SELECT t.$pk AS id FROM $vTable v JOIN $table t ON t.$fk = v.$vPk WHERE $sql", $p];
        }
    }
    if (!$selects) {
        return trim($search) === '' ? ['', []] : [" JOIN (SELECT NULL AS id FROM DUAL WHERE 1=0) sm ON sm.id = $alias.$pk", []];
    }
    $params = [];
    foreach ($selects as [, $p]) {
        array_push($params, ...$p);
    }
    return [' JOIN (' . implode(' UNION ', array_column($selects, 0)) . ") sm ON sm.id = $alias.$pk", $params];
}

/** Ids of one page, read from an index (ORDER BY must match an index for big tables). */
function page_ids(string $sql, array $params, int $limit, int $offset): array
{
    return array_map('intval', array_column(db_all("$sql LIMIT ? OFFSET ?", array_merge($params, [$limit, $offset])), 'id'));
}

/** Loads rows for the given ids and returns them in the same order. $sql must contain "IN (%s)". */
function rows_in_order(string $sql, array $ids, string $pk, array $params = []): array
{
    if (!$ids) {
        return [];
    }
    $rows = db_all(sprintf($sql, implode(',', array_fill(0, count($ids), '?'))), array_merge($params, $ids));
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r[$pk]] = $r;
    }
    $out = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $out[] = $byId[$id];
        }
    }
    return $out;
}

/**
 * Search for list screens. When the other filters (date range, store, status...) leave only a few thousand
 * rows, those rows are simply checked with "contains" (LIKE '%text%'), which is quick and finds text anywhere.
 * When they leave a lot of rows (e.g. "all time" on millions of bills), the indexed matchers are used instead.
 *
 *   $baseSql    "FROM table alias ... WHERE ..." without the search (used to count the rows cheaply)
 *   $likeCols   columns for the "contains" check, e.g. ['i.invoice_number', 'i.customer_name']
 *   $spec       see search_matchers()
 * Returns [joinSql, joinParams, whereSql, whereParams]; SQL order is "FROM t alias{join} WHERE base{where}".
 */
function list_search(string $search, string $table, string $pk, string $alias, array $likeCols, array $spec, string $baseSql, array $baseParams, int $threshold = 20000): array
{
    $search = trim(mb_substr($search, 0, 100));
    if ($search === '') {
        return ['', [], '', []];
    }
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $contains = [' AND (' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $likeCols)) . ')', array_fill(0, count($likeCols), $like)];
    $rows = (int) db_value('SELECT COUNT(*) FROM (SELECT 1 ' . $baseSql . ' LIMIT ' . ($threshold + 1) . ') t', $baseParams);
    if ($rows <= $threshold) {
        return ['', [], ...$contains];
    }
    // A very common word (a surname on thousands of bills): the index finds every match of all time and each one
    // must then be read and checked against the other filters. Checking the rows of the filters (e.g. a date range)
    // directly is cheaper then. The quick capped counts below tell the two cases apart.
    if (search_match_estimate($search, $table, $spec) > 5000) {
        return ['', [], ...$contains];
    }
    [$join, $params] = search_join($search, $table, $pk, $alias, $spec);
    return [$join, $params, '', []];
}

/** Matches of the indexed search conditions, counted only up to $cap per condition (each count stops early). */
function search_match_estimate(string $search, string $table, array $spec, int $cap = 5000): int
{
    $n = 0;
    foreach (search_matchers($search, $spec) as [$sql, $p]) {
        $n += (int) db_value("SELECT COUNT(*) FROM (SELECT 1 FROM $table WHERE $sql LIMIT " . ($cap + 1) . ') t', $p);
        if ($n > $cap) {
            return $n;
        }
    }
    if (!empty($spec['via'])) {
        [$vTable, , , $vSpec] = $spec['via'];
        foreach (search_matchers($search, $vSpec) as [$sql, $p]) {
            $n += (int) db_value("SELECT COUNT(*) FROM (SELECT 1 FROM $vTable WHERE $sql LIMIT " . ($cap + 1) . ') t', $p);
            if ($n > $cap) {
                return $n;
            }
        }
    }
    return $n;
}
