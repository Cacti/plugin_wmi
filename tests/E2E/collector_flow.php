<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Drives the collector against a real Cacti installation and its database,
 * with a fake wmic executable standing in for the Windows host. Run by
 * CollectorFlowTest.php in its own process; prints one JSON document of
 * observations on the last line of stdout.
 *
 * Every row it creates uses ids from 900001 upward and is removed again.
 */

/*
 * tests/ ships inside the plugin directory, so this file must do nothing when
 * a web server runs it. It needs the CLI and a work directory that the test
 * process names and creates; it takes no path or command from a request.
 */
$work = getenv('WMI_E2E_WORKDIR');

if (PHP_SAPI !== 'cli' || getenv('WMI_E2E') !== '1' || $work === false || !is_dir($work)) {
	http_response_code(404);

	exit;
}

$root = dirname(__DIR__, 4);

chdir($root);

$_SERVER['argv'] = ['collector_flow.php'];

require_once $root . '/include/cli_check.php';
require_once $root . '/plugins/wmi/includes/database.php';
require_once $root . '/plugins/wmi/includes/functions.php';

const ID = 900001;

$out   = [];
$saved = [];

/** Write the canned wmic output and exit status the fake executable will use. */
function fake_wmic_script(string $work, array $lines, int $status): void {
	file_put_contents($work . '/out', implode("\n", $lines) . "\n");
	file_put_contents($work . '/status', (string) $status);
}

/** @return array<int, string> The wmi_index values currently cached. */
function cached_indexes(): array {
	return array_column(db_fetch_assoc_prepared(
		"SELECT DISTINCT wmi_index FROM host_wmi_cache WHERE host_id = ? AND wmi_query_id = ? AND field_name = 'Name' ORDER BY wmi_index",
		[ID, ID]
	), 'wmi_index');
}

function cleanup(string $work, array $saved): void {
	db_execute_prepared('DELETE FROM host_wmi_cache WHERE host_id = ?', [ID]);
	db_execute_prepared('DELETE FROM host_wmi_query WHERE host_id = ?', [ID]);
	db_execute_prepared('DELETE FROM host_template_wmi_query WHERE wmi_query_id = ?', [ID]);
	db_execute_prepared('DELETE FROM wmi_wql_queries WHERE id = ?', [ID]);
	db_execute_prepared('DELETE FROM wmi_user_accounts WHERE id = ?', [ID]);
	db_execute_prepared('DELETE FROM host WHERE id = ?', [ID]);
	db_execute('DELETE FROM wmi_processes');

	foreach (['path_wmi', 'log_verbosity', 'wmi_processes'] as $name) {
		if (isset($saved[$name]) && $saved[$name] !== false) {
			db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', [$name, $saved[$name]]);
		} else {
			db_execute_prepared('DELETE FROM settings WHERE name = ?', [$name]);
		}
	}

	if (!empty($saved['plugin_row_added'])) {
		db_execute_prepared("DELETE FROM plugin_config WHERE directory = 'wmi'");
	}

	foreach (['wmic', 'out', 'status', 'argv'] as $name) {
		if (is_file($work . '/' . $name)) {
			unlink($work . '/' . $name);
		}
	}
}

/*
 * The failure case logs a WARNING on purpose, and the CI workflow fails a job
 * whose cacti.log holds any WARNING, ERROR or FATAL line. Remember how long
 * the log was and cut it back to that length when the run ends, keeping the
 * file's owner and mode.
 */
$log_file   = read_config_option('path_cactilog') ?: $root . '/log/cacti.log';
$log_exists = is_file($log_file);
$log_before = $log_exists ? (int) filesize($log_file) : 0;

register_shutdown_function(function () use ($work, &$saved, $log_file, $log_exists, $log_before) {
	cleanup($work, $saved);

	clearstatcache(true, $log_file);

	if (!is_file($log_file)) {
		return;
	}

	if (!$log_exists) {
		unlink($log_file);
	} elseif (filesize($log_file) > $log_before) {
		$handle = fopen($log_file, 'r+');

		if ($handle !== false) {
			ftruncate($handle, $log_before);
			fclose($handle);
		}
	}
});

plugin_wmi_setup_tables();

foreach (['path_wmi', 'log_verbosity', 'wmi_processes'] as $name) {
	$saved[$name] = db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', [$name]) ?: false;
}

cleanup($work, []);

if (db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_config WHERE directory = 'wmi'") == 0) {
	db_execute("INSERT INTO plugin_config (directory, name, status, author, webpage, version) VALUES ('wmi', 'wmi', 1, '', '', '1')");

	$saved['plugin_row_added'] = true;
}

file_put_contents($work . '/wmic', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($work . '/argv') . "\ncat " . escapeshellarg($work . '/out') . "\nexit \$(cat " . escapeshellarg($work . '/status') . ")\n");
chmod($work . '/wmic', 0700);

db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', ['path_wmi', $work . '/wmic']);
db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', ['log_verbosity', (string) POLLER_VERBOSITY_HIGH]);

$wmi = new Linux_WMI();

db_execute_prepared("INSERT INTO wmi_user_accounts (id, name, username, password) VALUES (?, 'e2e', 'dom\\\\user', ?)", [ID, $wmi->encode('pw')]);
db_execute_prepared("INSERT INTO host (id, description, hostname, host_template_id, status, disabled, wmi_account) VALUES (?, 'wmi-e2e', 'winsrv.invalid', ?, 3, '', ?)", [ID, ID, ID]);
db_execute_prepared("INSERT INTO wmi_wql_queries (id, hash, name, frequency, enabled, namespace, query, primary_key) VALUES (?, '', 'e2e', 60, 'on', ?, 'SELECT Name,State FROM Win32_Service', 'Name')", [ID, 'root\\\\CIMV2']);
db_execute_prepared('INSERT INTO host_template_wmi_query (host_template_id, wmi_query_id) VALUES (?, ?)', [ID, ID]);

$sep = '|+|';

// First refresh: three services are cached.
fake_wmic_script($work, ['Win32_Service', 'Name' . $sep . 'State', 'A' . $sep . 'Running', 'B' . $sep . 'Running', 'C' . $sep . 'Stopped'], 0);
ob_start();
$out['first_ok']       = run_store_wmi_query(ID, ID);
ob_end_clean();
$out['first_indexes']  = cached_indexes();
$out['namespace_args'] = array_values(array_filter(explode("\n", (string) file_get_contents($work . '/argv')), fn ($a) => strpos($a, '--namespace=') === 0));

// Second refresh: service C is gone and must be purged.
fake_wmic_script($work, ['Win32_Service', 'Name' . $sep . 'State', 'A' . $sep . 'Running', 'B' . $sep . 'Stopped'], 0);
ob_start();
$out['second_ok']      = run_store_wmi_query(ID, ID);
ob_end_clean();
$out['second_indexes'] = cached_indexes();

// A failing query keeps the cached rows and logs the reason.
fake_wmic_script($work, ['Login to remote object.'], 1);
ob_start();
$out['failed_ok']      = run_store_wmi_query(ID, ID);
ob_end_clean();
$out['failed_indexes'] = cached_indexes();
$out['failed_present'] = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_wmi_cache WHERE host_id = ? AND present = 0', [ID]);

clearstatcache(true, $log_file);
$log = (string) substr((string) @file_get_contents($log_file), $log_before);

$out['failure_logged'] = strpos($log, 'WMI Query[' . ID . '] for Device[' . ID . '] failed: ERROR: Login to remote object.') !== false;

// A two-column primary key keeps both rows that share a Name.
db_execute_prepared("UPDATE wmi_wql_queries SET primary_key = 'Name,State' WHERE id = ?", [ID]);
fake_wmic_script($work, ['Win32_Service', 'Name' . $sep . 'State', 'A' . $sep . 'Running', 'A' . $sep . 'Stopped'], 0);
ob_start();
run_store_wmi_query(ID, ID);
ob_end_clean();
$out['composite_indexes'] = cached_indexes();

// Master poller: a fresh lock row blocks the run, a stale one is cleared.
db_execute_prepared("UPDATE wmi_wql_queries SET primary_key = 'Name' WHERE id = ?", [ID]);
db_execute_prepared('DELETE FROM host_wmi_cache WHERE host_id = ?', [ID]);
fake_wmic_script($work, ['Win32_Service', 'Name' . $sep . 'State', 'A' . $sep . 'Running'], 0);

// An unset concurrency setting must not stall the master; bound the run anyway.
$limit  = trim((string) shell_exec('command -v timeout')) !== '' ? 'timeout 120 ' : '';
$poller = $limit . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/plugins/wmi/poller_wmi.php') . ' -M 2>&1';

db_execute_prepared("DELETE FROM settings WHERE name = 'wmi_processes'");
db_execute('DELETE FROM wmi_processes');
db_execute('INSERT INTO wmi_processes (pid, taskid, started) VALUES (1, 1, NOW())');
$out['fresh_lock_output']  = shell_exec($poller);
$out['fresh_lock_indexes'] = cached_indexes();

db_execute('DELETE FROM wmi_processes');
db_execute('INSERT INTO wmi_processes (pid, taskid, started) VALUES (1, 1, DATE_SUB(NOW(), INTERVAL 2 HOUR))');
$out['stale_lock_output']  = shell_exec($poller);
$out['stale_lock_indexes'] = cached_indexes();
$out['locks_left']         = (int) db_fetch_cell('SELECT COUNT(*) FROM wmi_processes');

print "\n" . json_encode($out) . "\n";
