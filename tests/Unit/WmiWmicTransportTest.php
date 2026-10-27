<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The wmic transport against a stand-in executable: success, output on
 * stdout with a failing exit status, no output at all, and a missing binary.
 */

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($string, $quote = true) {
		return escapeshellarg($string);
	}
}

require_once __DIR__ . '/../../includes/linux_wmi.php';

/**
 * Create an executable that prints the given lines and exits with a status.
 *
 * @param array<int, string> $lines
 */
function wmi_test_fake_wmic(array $lines, int $status): string {
	$dir  = sys_get_temp_dir() . '/wmi-test-' . bin2hex(random_bytes(6));
	$path = $dir . '/wmic';

	mkdir($dir, 0700);
	file_put_contents($path, "#!/bin/sh\n" . ($lines === [] ? '' : 'printf \'%s\\n\' ' . implode(' ', array_map('escapeshellarg', $lines)) . "\n") . 'exit ' . $status . "\n");
	chmod($path, 0700);

	return $path;
}

function wmi_test_run_wmic(string $binary): Linux_WMI {
	$w              = new Linux_WMI('', new Wmic_Shell_Transport());
	$w->username    = 'u';
	$w->password    = 'p';
	$w->hostname    = 'host';
	$w->command     = 'SELECT 1';
	$w->binary      = $binary;

	$w->fetch();

	return $w;
}

afterAll(function () {
	foreach (glob(sys_get_temp_dir() . '/wmi-test-*') ?: [] as $dir) {
		foreach (glob($dir . '/*') ?: [] as $file) {
			unlink($file);
		}

		rmdir($dir);
	}
});

it('returns the lines the executable printed on success', function () {
	$w = wmi_test_run_wmic(wmi_test_fake_wmic(['CLASS: Win32_Service', 'Name|+|State', 'A|+|Running'], 0));

	expect($w->error)->toBeFalse();
	expect($w->fetch_class())->toBe('CLASS: Win32_Service');
	expect($w->fetch_data())->toBe([['A', 'Running']]);
});

it('reports the output as the error when the executable fails', function () {
	$w = wmi_test_run_wmic(wmi_test_fake_wmic(['NTSTATUS: access denied'], 1));

	expect($w->error)->toBe('ERROR: NTSTATUS: access denied');
});

it('reports an empty error when the executable fails without output', function () {
	$w = wmi_test_run_wmic(wmi_test_fake_wmic([], 3));

	expect($w->error)->toBe('ERROR: ');
});

it('reports no data when the executable succeeds without output', function () {
	$w = wmi_test_run_wmic(wmi_test_fake_wmic([], 0));

	expect($w->error)->toBe('ERROR: WMI Returned no Data');
});

it('fails cleanly when the executable does not exist', function () {
	$w = wmi_test_run_wmic('/nonexistent/wmic-for-tests');

	expect($w->error)->toStartWith('ERROR: ');
	expect($w->fetch_data())->toBe([]);
});

it('refuses to run without credentials', function () {
	$w           = new Linux_WMI('', new Wmic_Shell_Transport());
	$w->command  = 'SELECT 1';

	expect($w->fetch())->toBeFalse();
	expect($w->error)->toBe('ERROR: Username or Password not set!');
});
