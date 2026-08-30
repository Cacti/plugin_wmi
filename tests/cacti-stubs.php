<?php
/*
 * Signature stubs for the Cacti core functions the plugin calls, so PHPStan can
 * resolve them without a full Cacti checkout. Not loaded at runtime.
 */

/** @param array<int|string, mixed> $params */
function db_fetch_row_prepared(string $sql, array $params = [], bool $log = true): array|false {
}

function cacti_escapeshellarg(string $string, bool $quote = true): string {
}
