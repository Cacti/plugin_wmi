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

$wmi_e2e_root = dirname(__DIR__, 4);

$wmi_e2e_ready = getenv('WMI_E2E') === '1' && is_readable($wmi_e2e_root . '/include/config.php');

/** @return array<string, mixed> */
function wmi_e2e_observations(string $root): array {
	static $cache = null;

	if ($cache === null) {
		$output = [];
		$status = 0;
		$work   = sys_get_temp_dir() . '/wmi-e2e-' . bin2hex(random_bytes(6));

		mkdir($work, 0700);

		exec('WMI_E2E=1 WMI_E2E_WORKDIR=' . escapeshellarg($work) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/plugins/wmi/tests/E2E/collector_flow.php') . ' 2>&1', $output, $status);

		@rmdir($work);

		$json  = json_decode((string) end($output), true);
		$cache = is_array($json) ? $json : ['driver_failed' => implode("\n", $output)];
	}

	return $cache;
}

describe('collector flow against a real Cacti', function () use ($wmi_e2e_root, $wmi_e2e_ready) {
	beforeEach(function () use ($wmi_e2e_ready) {
		if (!$wmi_e2e_ready) {
			$this->markTestSkipped('Set WMI_E2E=1 with an installed Cacti and database to run.');
		}
	});

	it('runs the driver to completion', function () use ($wmi_e2e_root) {
		expect(wmi_e2e_observations($wmi_e2e_root))->not->toHaveKey('driver_failed');
	});

	it('caches every row of the first refresh', function () use ($wmi_e2e_root) {
		$o = wmi_e2e_observations($wmi_e2e_root);

		expect($o['first_ok'])->toBeTrue();
		expect($o['first_indexes'])->toBe(['A', 'B', 'C']);
	});

	it('passes the namespace to wmic with a single backslash', function () use ($wmi_e2e_root) {
		expect(wmi_e2e_observations($wmi_e2e_root)['namespace_args'])->toBe(['--namespace=root\\CIMV2']);
	});

	it('purges a row the device stopped returning', function () use ($wmi_e2e_root) {
		expect(wmi_e2e_observations($wmi_e2e_root)['second_indexes'])->toBe(['A', 'B']);
	});

	it('keeps the cached rows intact when a query fails and logs why', function () use ($wmi_e2e_root) {
		$o = wmi_e2e_observations($wmi_e2e_root);

		expect($o['failed_ok'])->toBeFalse();
		expect($o['failed_indexes'])->toBe(['A', 'B']);
		expect($o['failed_present'])->toBe(0);
		expect($o['failure_logged'])->toBeTrue();
	});

	it('keeps both rows that share a name when the primary key has two columns', function () use ($wmi_e2e_root) {
		expect(wmi_e2e_observations($wmi_e2e_root)['composite_indexes'])->toBe(['A,Running', 'A,Stopped']);
	});

	it('does not start while a recent collector lock exists', function () use ($wmi_e2e_root) {
		$o = wmi_e2e_observations($wmi_e2e_root);

		expect($o['fresh_lock_output'])->toContain('still running');
		expect($o['fresh_lock_indexes'])->toBe([]);
	});

	it('clears a stale collector lock and polls the device', function () use ($wmi_e2e_root) {
		$o = wmi_e2e_observations($wmi_e2e_root);

		expect($o['stale_lock_output'])->toContain('All Processes Complete');
		expect($o['stale_lock_indexes'])->toBe(['A']);
		expect($o['locks_left'])->toBe(0);
	});
});

describe('collector flow entry script', function () {
	it('does nothing and prints nothing without the test environment', function () {
		$script = __DIR__ . '/collector_flow.php';
		$output = [];
		$status = 0;

		exec('env -u WMI_E2E -u WMI_E2E_WORKDIR ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);

		expect($output)->toBe([]);
		expect($status)->toBe(0);
	});

	it('does nothing when the work directory is missing', function () {
		$script = __DIR__ . '/collector_flow.php';
		$output = [];

		exec('WMI_E2E=1 WMI_E2E_WORKDIR=/nonexistent-wmi-e2e ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output);

		expect($output)->toBe([]);
	});
});
