<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Drives the cache refresh and the poller_wmi.php master and child against a
 * real Cacti installation and its database, with a fake wmic executable
 * standing in for the Windows hosts. Run by CollectorFlowTest.php in its own
 * process; prints one JSON document of observations on the last line.
 *
 * tests/ ships inside the plugin directory, so this file must do nothing when
 * a web server runs it. It needs the CLI and a work directory that the test
 * process names and creates; it takes no path or command from a request.
 */
$work = getenv('WMI_E2E_WORKDIR');

if (PHP_SAPI !== 'cli' || getenv('WMI_E2E') !== '1' || $work === false || !is_dir($work)) {
	http_response_code(404);

	exit;
}

require_once __DIR__ . '/e2e_common.php';

wmi_e2e_boot();

$root = $GLOBALS['wmi_e2e']['root'];
$out  = [];

const HOST_A    = 900001;
const HOST_B    = 900002;
const HOST_C    = 900003;
const QUERY_SVC = 900001;
const QUERY_PRC = 900002;
const TEMPLATE  = 900001;
const ACCOUNT   = 900001;

$svc_header = ['Name', 'State'];

wmi_e2e_add_account(ACCOUNT, 'e2e', 'dom\\user', 'pw');
wmi_e2e_add_host(HOST_A, TEMPLATE, ACCOUNT, 'winsrv1.invalid');
wmi_e2e_add_query(QUERY_SVC, 'e2e-svc', 'root\\\\CIMV2', 'SELECT Name,State FROM Win32_Service', 'Name');

/**
 * Run the refresh quietly and report the outcome with the cache state.
 *
 * @return array<string, mixed>
 */
function refresh(int $host = HOST_A, int $query = QUERY_SVC): array {
	$result = wmi_e2e_quiet(fn () => run_store_wmi_query($host, $query));

	return [
		'result'   => $result,
		'indexes'  => wmi_e2e_cached($host, $query),
		'rows'     => wmi_e2e_cache_count($host, $query),
		'present0' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_wmi_cache WHERE host_id = ? AND wmi_query_id = ? AND present = 0', [$host, $query]),
		'argv'     => wmi_e2e_last_argv(),
	];
}

// First refresh caches every row.
wmi_e2e_wmic_default(wmi_e2e_result('Win32_Service', $svc_header, [['A', 'Running'], ['B', 'Running'], ['C', 'Stopped']]), 0);
$out['first'] = refresh();

// The device drops an instance, which is purged.
wmi_e2e_wmic_default(wmi_e2e_result('Win32_Service', $svc_header, [['A', 'Running'], ['B', 'Stopped']]), 0);
$out['dropped'] = refresh();
$out['state_b'] = db_fetch_cell_prepared("SELECT field_value FROM host_wmi_cache WHERE host_id = ? AND wmi_query_id = ? AND field_name = 'State' AND wmi_index = 'B'", [HOST_A, QUERY_SVC]);

// Failures keep the cached rows: partial output then non-zero exit, empty
// output, non-zero exit with no output, and a header without rows.
wmi_e2e_wmic_default(['Win32_Service', 'Name' . WMI_E2E_SEP . 'State', 'A' . WMI_E2E_SEP . 'Running'], 1);
$out['midway'] = refresh();

wmi_e2e_wmic_default([], 0);
$out['empty_output'] = refresh();

wmi_e2e_wmic_default([], 3);
$out['silent_failure'] = refresh();

wmi_e2e_wmic_default(['Win32_Service', 'Name' . WMI_E2E_SEP . 'State'], 0);
$out['header_only'] = refresh();

// Primary key shapes: duplicate values of a single key, None with one and
// with several rows, a composite key with and without spaces, and one naming
// a column the result does not have.
$key_cases = [
	'duplicate_single' => ['Name', [['A', 'Running'], ['A', 'Stopped']]],
	'none_one_row'     => ['None', [['A', 'Running']]],
	'none_many_rows'   => ['None', [['A', 'Running'], ['B', 'Running']]],
	'composite'        => ['Name,State', [['A', 'Running'], ['A', 'Stopped']]],
	'composite_spaced' => ['Name, State', [['A', 'Running'], ['A', 'Stopped']]],
	'unknown_column'   => ['Name,Nope', [['A', 'Running'], ['B', 'Running']]],
];

foreach ($key_cases as $label => [$key, $rows]) {
	db_execute_prepared('UPDATE wmi_wql_queries SET primary_key = ? WHERE id = ?', [$key, QUERY_SVC]);
	db_execute_prepared('DELETE FROM host_wmi_cache WHERE host_id = ?', [HOST_A]);
	wmi_e2e_wmic_default(wmi_e2e_result('Win32_Service', $svc_header, $rows), 0);

	$out['key_' . $label] = refresh();
}

$out['key_duplicate_state'] = null;

db_execute_prepared('UPDATE wmi_wql_queries SET primary_key = ? WHERE id = ?', ['Name', QUERY_SVC]);
db_execute_prepared('DELETE FROM host_wmi_cache WHERE host_id = ?', [HOST_A]);
wmi_e2e_wmic_default(wmi_e2e_result('Win32_Service', $svc_header, [['A', 'Running'], ['A', 'Stopped']]), 0);
refresh();
$out['key_duplicate_state'] = db_fetch_cell_prepared("SELECT field_value FROM host_wmi_cache WHERE host_id = ? AND wmi_query_id = ? AND field_name = 'State'", [HOST_A, QUERY_SVC]);

// Namespace forms as they reach wmic.
$out['namespaces'] = [];

foreach (['double' => 'root\\\\CIMV2', 'single' => 'root\\CIMV2', 'empty' => '', 'cluster' => 'root\\\\MSCluster'] as $label => $namespace) {
	db_execute_prepared('UPDATE wmi_wql_queries SET namespace = ? WHERE id = ?', [$namespace, QUERY_SVC]);
	wmi_e2e_wmic_default(wmi_e2e_result('Win32_Service', $svc_header, [['A', 'Running']]), 0);
	refresh();

	$out['namespaces'][$label] = array_values(array_filter(wmi_e2e_last_argv(), fn ($a) => strpos($a, '--namespace=') === 0));
}

db_execute_prepared('UPDATE wmi_wql_queries SET namespace = ? WHERE id = ?', ['root\\\\CIMV2', QUERY_SVC]);

// Account, query and host problems leave the cache alone or clear it.
wmi_e2e_wmic_default(wmi_e2e_result('Win32_Service', $svc_header, [['A', 'Running'], ['B', 'Running']]), 0);
db_execute_prepared('DELETE FROM host_wmi_cache WHERE host_id = ?', [HOST_A]);
refresh();

db_execute_prepared('UPDATE host SET wmi_account = 0 WHERE id = ?', [HOST_A]);
$out['no_account'] = refresh();
db_execute_prepared('UPDATE host SET wmi_account = ? WHERE id = ?', [ACCOUNT, HOST_A]);

$out['no_query'] = wmi_e2e_quiet(fn () => run_store_wmi_query(HOST_A, QUERY_SVC + 50));
$out['no_query_rows'] = wmi_e2e_cache_count(HOST_A, QUERY_SVC);

db_execute_prepared('DELETE FROM host WHERE id = ?', [HOST_A]);
$out['no_host'] = refresh();

$out['log'] = wmi_e2e_log_since_boot();

// Poller: three hosts, two queries, one failing host/query pair.
db_execute_prepared('DELETE FROM host_wmi_cache WHERE host_id >= ?', [HOST_A]);
db_execute_prepared('DELETE FROM wmi_wql_queries WHERE id >= ?', [QUERY_SVC]);
wmi_e2e_add_query(QUERY_SVC, 'e2e-svc', 'root\\\\CIMV2', 'SELECT Name,State FROM Win32_Service', 'Name');
wmi_e2e_add_query(QUERY_PRC, 'e2e-prc', 'root\\\\CIMV2', 'SELECT ProcessId,Name FROM Win32_Process', 'ProcessId');
wmi_e2e_link_template(TEMPLATE, QUERY_SVC);
wmi_e2e_link_template(TEMPLATE, QUERY_PRC);

$hosts = [HOST_A => 'winsrv1.invalid', HOST_B => 'winsrv2.invalid', HOST_C => 'winsrv3.invalid'];

foreach ($hosts as $id => $name) {
	wmi_e2e_add_host($id, TEMPLATE, ACCOUNT, $name);
}

$services  = wmi_e2e_result('Win32_Service', $svc_header, [['A', 'Running'], ['B', 'Running']]);
$processes = wmi_e2e_result('Win32_Process', ['ProcessId', 'Name'], [['10', 'sys'], ['11', 'app'], ['12', 'db']]);

$set_wmic = function () use ($services, $processes): void {
	wmi_e2e_wmic_default([], 0);
	wmi_e2e_wmic_rules([
		['winsrv3.invalid SELECT Name,State', 1, ['ERROR: access denied']],
		['Win32_Service', 0, $services],
		['Win32_Process', 0, $processes],
	]);
};

$set_wmic();

$php    = escapeshellarg(PHP_BINARY);
$script = escapeshellarg($root . '/plugins/wmi/poller_wmi.php');
$has_timeout = trim((string) shell_exec('command -v timeout')) !== '';

/**
 * Run the poller and return its output and exit status.
 *
 * @return array{output: string, status: int}
 */
$poller = function (string $arguments, int $seconds = 120) use ($php, $script, $has_timeout): array {
	$lines  = [];
	$status = 0;

	exec(($has_timeout ? 'timeout ' . $seconds . ' ' : '') . $php . ' ' . $script . ' ' . $arguments . ' 2>&1', $lines, $status);

	return ['output' => implode("\n", $lines), 'status' => $status];
};

$poll_state = function () use ($hosts): array {
	$state = ['locks' => (int) db_fetch_cell('SELECT COUNT(*) FROM wmi_processes'), 'cache' => []];

	foreach (array_keys($hosts) as $id) {
		$state['cache'][$id] = [
			'svc' => wmi_e2e_cached($id, QUERY_SVC),
			'prc' => array_column(db_fetch_assoc_prepared("SELECT DISTINCT wmi_index FROM host_wmi_cache WHERE host_id = ? AND wmi_query_id = ? AND field_name = 'Name' ORDER BY wmi_index", [$id, QUERY_PRC]), 'wmi_index'),
		];
	}

	return $state;
};

$reset = function () use ($hosts): void {
	db_execute_prepared('DELETE FROM host_wmi_cache WHERE host_id >= ?', [HOST_A]);
	db_execute_prepared('DELETE FROM host_wmi_query WHERE host_id >= ?', [HOST_A]);
	db_execute('DELETE FROM wmi_processes');
	db_execute_prepared('UPDATE host SET status = 3, disabled = ? WHERE id >= ?', ['', HOST_A]);
	db_execute_prepared('UPDATE wmi_wql_queries SET enabled = ? WHERE id >= ?', ['on', QUERY_SVC]);
	db_execute_prepared('UPDATE plugin_config SET status = 1 WHERE directory = ?', ['wmi']);
};

// A recent lock blocks the run.
$reset();
db_execute('INSERT INTO wmi_processes (pid, taskid, started) VALUES (1, 1, NOW())');
$out['live_lock'] = $poller('-M') + ['state' => $poll_state()];

// A stale lock is cleared, and the setting being absent still launches the
// collectors.
$reset();
db_execute_prepared('DELETE FROM settings WHERE name = ?', ['wmi_processes']);
db_execute('INSERT INTO wmi_processes (pid, taskid, started) VALUES (1, 1, DATE_SUB(NOW(), INTERVAL 2 HOUR))');
$out['stale_lock'] = $poller('-M') + ['state' => $poll_state()];
$out['stale_lock']['last_failed'] = db_fetch_cell_prepared('SELECT last_failed FROM host_wmi_query WHERE host_id = ? AND wmi_query_id = ?', [HOST_C, QUERY_SVC]);
$out['stale_lock']['last_failed_ok'] = db_fetch_cell_prepared('SELECT last_failed FROM host_wmi_query WHERE host_id = ? AND wmi_query_id = ?', [HOST_A, QUERY_SVC]);

// A second run right away finds nothing due.
$out['not_due'] = $poller('-M');

// Concurrency settings: empty string, one process, a larger value.
foreach (['empty' => '', 'one' => '1', 'many' => '8'] as $label => $value) {
	$reset();
	db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', ['wmi_processes', $value]);
	$out['concurrency_' . $label] = $poller('-M') + ['state' => $poll_state()];
}

// A collector that cannot start leaves its placeholder row. The master keeps
// waiting, the next run sees a live lock, and once the row is old it is
// cleared and the devices are polled.
$reset();
db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', ['path_php_binary', '/bin/false']);
$out['child_dies'] = $poller('-M', 8) + ['state' => $poll_state()];
db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', ['path_php_binary', $GLOBALS['wmi_e2e']['saved']['path_php_binary'] ?: PHP_BINARY]);
$out['after_dead_child_live'] = $poller('-M') + ['state' => $poll_state()];
db_execute('UPDATE wmi_processes SET started = DATE_SUB(NOW(), INTERVAL 2 HOUR)');
$out['after_dead_child_aged'] = $poller('-M') + ['state' => $poll_state()];

// Devices that are down, disabled or without an enabled query are skipped,
// and the cache of a down device is dropped.
$reset();
db_execute_prepared('UPDATE host SET status = 1 WHERE id = ?', [HOST_A]);
db_execute_prepared('UPDATE host SET disabled = ? WHERE id = ?', ['on', HOST_B]);
db_execute_prepared("INSERT INTO host_wmi_cache (host_id, wmi_query_id, field_name, field_value, wmi_index, present) VALUES (?, ?, 'Name', 'old', 'old', 1)", [HOST_A, QUERY_SVC]);
$out['skipped_hosts'] = $poller('-M') + ['state' => $poll_state()];

$reset();
db_execute_prepared('UPDATE wmi_wql_queries SET enabled = ? WHERE id >= ?', ['', QUERY_SVC]);
$out['no_enabled_query'] = $poller('-M') + ['state' => $poll_state()];

// The plugin disabled in Plugin Management.
$reset();
db_execute_prepared('UPDATE plugin_config SET status = 0 WHERE directory = ?', ['wmi']);
$out['plugin_disabled'] = $poller('-M') + ['state' => $poll_state()];

// A child run by itself, and the command line.
$reset();
$out['child_alone']    = $poller('--host-id=' . HOST_B . ' --debug --force') + ['state' => $poll_state()];
$out['child_no_id']    = $poller('');
$out['bad_parameter']  = $poller('--bogus');
$out['version']        = $poller('--version');
$out['help']           = $poller('--help');

$out['log_after'] = wmi_e2e_log_since_boot();

print "\n" . json_encode($out) . "\n";
