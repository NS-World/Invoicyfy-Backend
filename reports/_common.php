<?php
/** Builds a "store filter" SQL fragment for a table alias, honouring the user's store scope. */
function store_filter(array $user, string $alias): array
{
    $scope = scope_store($user, $_GET['store_id'] ?? null);
    return $scope === null ? ['', []] : [" AND $alias.store_id = ?", [$scope]];
}

function f2(array $rows, array $floats, array $ints = []): array
{
    return array_map(fn($r) => cast_numbers($r, $floats, $ints), $rows);
}
