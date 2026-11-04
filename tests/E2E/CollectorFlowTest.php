<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * End-to-end collector flow: a real Cacti installation and database, the
 * real poller_wmi.php master and child processes, and a fake wmic executable.
 * It needs the installed Cacti tree and database the CI workflow starts, so
 * it runs only when WMI_E2E=1 and include/config.php is present.
 */

require_once __DIR__ . '/runner.php';

/** @return array<string, mixed> */
function wmi_e2e_collector(): array {
	return wmi_e2e_run('collector_flow.php');
}

describe('cache refresh against a real Cacti', function () {
	beforeEach(function () {
		$reason = wmi_e2e_unready();

		if ($reason !== '') {
			$this->markTestSkipped($reason);
		}
	});

	it('runs the driver to completion', function () {
		// Comparing the text, not the key, puts the driver's output in the failure.
		expect(wmi_e2e_collector()['driver_failed'] ?? '')->toBe('');
	});

	it('caches every row of the first refresh', function () {
		$o = wmi_e2e_collector()['first'];

		expect($o['result'])->toBeTrue();
		expect($o['indexes'])->toBe(['A', 'B', 'C']);
		expect($o['rows'])->toBe(6);
		expect($o['present0'])->toBe(0);
	});

	it('passes the credentials and query to wmic', function () {
		$argv = wmi_e2e_collector()['first']['argv'];

		expect($argv)->not->toContain('--user=dom\\user');
		expect($argv)->not->toContain('--password=pw');
		expect($argv)->toContain('//winsrv1.invalid');
		expect($argv)->toContain('SELECT Name,State FROM Win32_Service');
	});

	it('hands the credentials to wmic in a private file that is gone afterwards', function () {
		$o = wmi_e2e_collector();

		expect(array_filter($o['first']['argv'], fn ($a) => strpos($a, '--authentication-file=') === 0))->toHaveCount(1);
		expect($o['first_auth']['content'])->toBe("username=user\npassword=pw\ndomain=dom\n");
		expect($o['first_auth']['mode'])->toBe('600');
		expect($o['first_auth']['exists'])->toBeFalse();
	});

	it('runs the script-server scripts from the command line against the cache data', function (string $script) {
		$o = wmi_e2e_collector()['script_' . $script];

		expect($o['index'])->toContain('A!A');
		expect($o['index'])->toContain('C!C');
		expect($o['pair'])->toContain('State!Stopped');
		expect($o['list'])->toContain('B');
		expect($o['none'])->toBe('');
	})->with(['dir', 'root']);

	it('purges an instance the device stopped returning and updates the rest', function () {
		$o = wmi_e2e_collector();

		expect($o['dropped']['result'])->toBeTrue();
		expect($o['dropped']['indexes'])->toBe(['A', 'B']);
		expect($o['dropped']['rows'])->toBe(4);
		expect($o['dropped']['present0'])->toBe(0);
		expect($o['state_b'])->toBe('Stopped');
	});

	it('keeps the cached rows when the query fails in each way', function (string $case) {
		$o = wmi_e2e_collector()[$case];

		expect($o['result'])->toBeFalse();
		expect($o['indexes'])->toBe(['A', 'B']);
		expect($o['rows'])->toBe(4);
		expect($o['present0'])->toBe(0);
	})->with(['midway', 'empty_output', 'silent_failure', 'header_only']);

	it('indexes rows by the primary key shape', function (string $case, array $indexes, int $rows) {
		$o = wmi_e2e_collector()['key_' . $case];

		expect($o['result'])->toBeTrue();
		expect($o['indexes'])->toBe($indexes);
		expect($o['rows'])->toBe($rows);
	})->with([
		'duplicate single key keeps one row' => ['duplicate_single', ['A'], 2],
		'None with one row'                  => ['none_one_row', ['N/A'], 2],
		'None with several rows'             => ['none_many_rows', ['N/A'], 2],
		'composite key'                      => ['composite', ['A,Running', 'A,Stopped'], 4],
		'composite key with a space'         => ['composite_spaced', ['A,Running', 'A,Stopped'], 4],
		'unknown key column'                 => ['unknown_column', ['N/A'], 2],
	]);

	it('keeps the last row when two rows share a single-column key', function () {
		expect(wmi_e2e_collector()['key_duplicate_state'])->toBe('Stopped');
	});

	it('passes every namespace form to wmic with single backslashes', function (string $form, string $expected) {
		expect(wmi_e2e_collector()['namespaces'][$form])->toBe(['--namespace=' . $expected]);
	})->with([
		'doubled backslash' => ['double', 'root\\CIMV2'],
		'single backslash'  => ['single', 'root\\CIMV2'],
		'empty'             => ['empty', 'root\\CIMV2'],
		'another namespace' => ['cluster', 'root\\MSCluster'],
	]);

	it('leaves the cache alone without an account or a query', function () {
		$o = wmi_e2e_collector();

		expect($o['no_account']['result'])->toBeFalse();
		expect($o['no_account']['indexes'])->toBe(['A', 'B']);
		expect($o['no_query'])->toBeFalse();
		expect($o['no_query_rows'])->toBe(4);
	});

	it('drops the cached rows of a device that no longer exists', function () {
		$o = wmi_e2e_collector()['no_host'];

		expect($o['result'])->toBeNull();
		expect($o['rows'])->toBe(0);
	});

	it('logs why a query failed', function () {
		$log = wmi_e2e_collector()['log'];

		expect($log)->toContain('WMI Query[900001] for Device[900001] failed: ERROR: WMI Returned no Data');
		expect($log)->toContain('failed: ERROR: ');
		expect($log)->toContain('failed: WMI returned no rows');
	});
});

describe('poller master and child against a real Cacti', function () {
	beforeEach(function () {
		$reason = wmi_e2e_unready();

		if ($reason !== '') {
			$this->markTestSkipped($reason);
		}
	});

	/** The cache every healthy device and query ends up with. */
	$polled = ['svc' => ['A', 'B'], 'prc' => ['10', '11', '12']];

	it('does not start while a recent collector lock exists', function () {
		$o = wmi_e2e_collector()['live_lock'];

		expect($o['output'])->toContain('still running');
		expect($o['state']['locks'])->toBe(1);

		foreach ($o['state']['cache'] as $cache) {
			expect($cache)->toBe(['svc' => [], 'prc' => []]);
		}
	});

	it('clears a stale lock, polls every device and query, and records the failing pair', function () use ($polled) {
		$o = wmi_e2e_collector()['stale_lock'];

		expect($o['output'])->toContain('All Processes Complete');
		expect($o['state']['locks'])->toBe(0);
		expect($o['state']['cache']['900001'])->toBe($polled);
		expect($o['state']['cache']['900002'])->toBe($polled);
		expect($o['state']['cache']['900003'])->toBe(['svc' => [], 'prc' => $polled['prc']]);
		expect($o['last_failed'])->not->toBe('0000-00-00 00:00:00');
		expect($o['last_failed_ok'])->toBe('0000-00-00 00:00:00');
	});

	it('finds nothing due right after a run', function () {
		expect(wmi_e2e_collector()['not_due']['output'])->toContain('No Devices found this pass');
	});

	it('polls all devices whatever the concurrency setting holds', function (string $case) use ($polled) {
		$o = wmi_e2e_collector()['concurrency_' . $case];

		expect($o['status'])->toBe(0);
		expect($o['output'])->toContain('All Processes Complete');
		expect($o['state']['locks'])->toBe(0);
		expect($o['state']['cache']['900001'])->toBe($polled);
		expect($o['state']['cache']['900002'])->toBe($polled);
	})->with(['empty', 'one', 'many']);

	it('keeps waiting while a collector cannot start, then recovers once its lock is old', function () use ($polled) {
		$o = wmi_e2e_collector();

		expect($o['child_dies']['status'])->toBe(124);
		expect($o['child_dies']['state']['locks'])->toBe(3);
		expect($o['after_dead_child_live']['output'])->toContain('still running');
		expect($o['after_dead_child_live']['state']['locks'])->toBe(3);
		expect($o['after_dead_child_aged']['output'])->toContain('All Processes Complete');
		expect($o['after_dead_child_aged']['state']['locks'])->toBe(0);
		expect($o['after_dead_child_aged']['state']['cache']['900001'])->toBe($polled);
	});

	it('skips down and disabled devices and drops the cache of a down device', function () use ($polled) {
		$o = wmi_e2e_collector()['skipped_hosts'];

		expect($o['output'])->toContain("wmi-e2e-900003[winsrv3.invalid]");
		expect($o['output'])->not->toContain('wmi-e2e-900001');
		expect($o['output'])->not->toContain('wmi-e2e-900002');
		expect($o['state']['cache']['900001'])->toBe(['svc' => [], 'prc' => []]);
		expect($o['state']['cache']['900002'])->toBe(['svc' => [], 'prc' => []]);
	});

	it('finds nothing to poll when no query is enabled', function () {
		expect(wmi_e2e_collector()['no_enabled_query']['output'])->toContain('No Devices found this pass');
	});

	it('does nothing while the plugin is disabled', function () {
		$o = wmi_e2e_collector()['plugin_disabled'];

		expect($o['output'])->toContain('Collection is Down');
		expect($o['state']['cache']['900001'])->toBe(['svc' => [], 'prc' => []]);
	});

	it('polls one device when run as a child, and reports bad command lines', function () use ($polled) {
		$o = wmi_e2e_collector();

		expect($o['child_alone']['state']['cache']['900002'])->toBe($polled);
		expect($o['child_alone']['state']['cache']['900001'])->toBe(['svc' => [], 'prc' => []]);
		expect($o['child_no_id']['output'])->toContain('You must specify a Cacti host-id');
		expect($o['bad_parameter']['output'])->toContain('Invalid Parameter --bogus');
		expect($o['version']['output'])->toContain('Device WMI Poller Process, Version');
		expect($o['help']['output'])->toContain('usage:');
	});
});
