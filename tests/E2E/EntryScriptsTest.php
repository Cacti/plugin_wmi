<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The end-to-end helper scripts live under tests/, which can end up in a web
 * root. They must do nothing, and print nothing, outside the test process.
 * These checks need no database and run everywhere.
 */

require_once __DIR__ . '/runner.php';

describe('end-to-end entry scripts', function () {
	it('do nothing and print nothing without the test environment', function (string $script) {
		$output = [];
		$status = 0;

		exec('env -u WMI_E2E -u WMI_E2E_WORKDIR ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $script) . ' 2>&1', $output, $status);

		expect($output)->toBe([]);
		expect($status)->toBe(0);
	})->with(['collector_flow.php', 'pages_flow.php', 'e2e_common.php', 'coverage_prepend.php']);

	it('do nothing when the work directory is missing', function (string $script) {
		$output = [];

		exec('WMI_E2E=1 WMI_E2E_WORKDIR=/nonexistent-wmi-e2e ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $script) . ' 2>&1', $output);

		expect($output)->toBe([]);
	})->with(['collector_flow.php', 'pages_flow.php', 'e2e_common.php']);

	it('record no coverage unless a coverage directory is named', function () {
		$work = sys_get_temp_dir() . '/wmi-e2e-' . bin2hex(random_bytes(4));

		mkdir($work, 0700);
		file_put_contents($work . '/probe.php', "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/includes/linux_wmi.php', true) . ";\necho 'done';\n");

		$output = [];

		exec('env -u WMI_COV_DIR ' . escapeshellarg(PHP_BINARY) . ' -d xdebug.mode=coverage -d auto_prepend_file=' . escapeshellarg(__DIR__ . '/coverage_prepend.php') . ' ' . escapeshellarg($work . '/probe.php') . ' 2>&1', $output);

		expect($output)->toBe(['done']);
		expect(glob($work . '/*.json'))->toBe([]);

		wmi_e2e_remove($work);
	});

	it('record the plugin lines that ran when a coverage directory is named', function () {
		if (!function_exists('xdebug_start_code_coverage')) {
			$this->markTestSkipped('Xdebug is not loaded.');
		}

		$work = sys_get_temp_dir() . '/wmi-e2e-' . bin2hex(random_bytes(4));

		mkdir($work, 0700);
		file_put_contents($work . '/probe.php', "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/includes/linux_wmi.php', true) . ";\necho 'done';\n");

		$output = [];

		exec('WMI_COV_DIR=' . escapeshellarg($work) . ' ' . escapeshellarg(PHP_BINARY) . ' -d xdebug.mode=coverage -d auto_prepend_file=' . escapeshellarg(__DIR__ . '/coverage_prepend.php') . ' ' . escapeshellarg($work . '/probe.php') . ' 2>&1', $output);

		$dumps = glob($work . '/*.json') ?: [];
		$data  = $dumps === [] ? [] : json_decode((string) file_get_contents($dumps[0]), true);

		expect($output)->toBe(['done']);
		expect($dumps)->toHaveCount(1);
		expect(array_keys($data))->toBe(['includes/linux_wmi.php']);

		wmi_e2e_remove($work);
	});
});
