<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * run_store_wmi_query() keeps host_wmi_cache in step with what the device
 * returns. Rows are flagged present = 0 before the refresh and purged after
 * it, but only when the refresh produced data.
 */

require_once __DIR__ . '/../../includes/functions.php';

/** A transport stub that returns canned lines, or fails with an error. */
class Wmi_Test_Canned_Transport implements Wmi_Transport {
	/** @param array<int, string>|false $lines */
	public function __construct(private array|false $lines, private ?string $message = null) {
	}

	public function query(Wmi_Request $request): array|false {
		return $this->lines;
	}

	public function error(): ?string {
		return $this->message;
	}
}

/** @return array<int, string> */
function wmi_test_cache_statements(): array {
	$out = array();

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		if (strpos($call['sql'] ?? '', 'host_wmi_cache') !== false) {
			$out[] = preg_replace('/\s+/', ' ', $call['sql']);
		}
	}

	return $out;
}

beforeEach(function () {
	$w = new Linux_WMI();

	$GLOBALS['__test_db_calls']       = array();
	$GLOBALS['__test_cacti_log']      = array();
	$GLOBALS['__test_fetch_row_map']  = array(
		'FROM host'         => array('id' => 5, 'hostname' => 'winsrv', 'wmi_account' => 1),
		'wmi_user_accounts' => array('username' => 'u', 'password' => $w->encode('pw')),
		'wmi_wql_queries'   => array('id' => 9, 'namespace' => 'root\\CIMV2', 'query' => 'SELECT Name,State FROM Win32_Service', 'primary_key' => 'Name'),
	);
});

it('purges cache rows the device stopped returning after a successful refresh', function () {
	$t = new Wmi_Test_Canned_Transport(array('Win32_Service', 'Name|+|State', 'Spooler|+|Running'));

	ob_start();
	$ok = run_store_wmi_query(5, 9, $t);
	ob_end_clean();

	$statements = wmi_test_cache_statements();

	expect($ok)->toBeTrue();
	expect($statements[0])->toContain('UPDATE host_wmi_cache SET present = 0');
	expect($statements[1])->toContain('INSERT INTO host_wmi_cache');
	expect($statements[2])->toContain('DELETE FROM host_wmi_cache WHERE present = 0');
});

it('leaves the cache untouched when the query fails', function () {
	$t = new Wmi_Test_Canned_Transport(false, 'ERROR: no route');

	ob_start();
	$ok = run_store_wmi_query(5, 9, $t);
	ob_end_clean();

	expect($ok)->toBeFalse();
	expect(wmi_test_cache_statements())->toBe(array());
});

it('drops the cached rows of a device that no longer exists', function () {
	$GLOBALS['__test_fetch_row_map'] = array();

	run_store_wmi_query(5, 9, new Wmi_Test_Canned_Transport(false));

	$statements = wmi_test_cache_statements();

	expect($statements)->toHaveCount(1);
	expect($statements[0])->toContain('DELETE FROM host_wmi_cache WHERE host_id = ? AND wmi_query_id = ?');
});

it('indexes a row by a single primary key column', function () {
	expect(wmi_row_index(array('Spooler', 'Running'), array('Name', 'State'), 'Name'))->toBe('Spooler');
});

it('indexes a row by every column of a comma separated primary key', function () {
	expect(wmi_row_index(array('C:', 'D1', 'x'), array('Drive', 'Disk', 'Size'), 'Drive, Disk'))->toBe('C:,D1');
});

it('uses the shared index when the key is None or missing from the header', function () {
	$header = array('Name', 'State');

	expect(wmi_row_index(array('a', 'b'), $header, 'None'))->toBe('N/A');
	expect(wmi_row_index(array('a', 'b'), $header, 'Name,Missing'))->toBe('N/A');
	expect(wmi_row_index(array('a'), $header, 'State'))->toBe('N/A');
});

it('caches every row of a result whose primary key spans two columns', function () {
	$GLOBALS['__test_fetch_row_map']['wmi_wql_queries']['primary_key'] = 'Name,State';

	$t = new Wmi_Test_Canned_Transport(array('Win32_Service', 'Name|+|State', 'Spooler|+|Running', 'Spooler|+|Stopped'));

	ob_start();
	run_store_wmi_query(5, 9, $t);
	ob_end_clean();

	$insert = '';

	foreach (wmi_test_cache_statements() as $statement) {
		if (strpos($statement, 'INSERT INTO') === 0) {
			$insert = $statement;
		}
	}

	expect($insert)->toContain("'Spooler,Running'");
	expect($insert)->toContain("'Spooler,Stopped'");
});
