<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The wmic transport reads stdout and stderr apart: only stdout is the
 * result, stderr is added to the error text, and a wmic that does not finish
 * is stopped. A stand-in executable prints canned output.
 */

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($string, $quote = true) {
		return escapeshellarg($string);
	}
}

require_once __DIR__ . '/../../includes/linux_wmi.php';

/** A transport whose process cannot be started. */
class Wmi_Test_Unstartable_Transport extends Wmic_Shell_Transport {
	protected function start_process(string $command, ?array &$pipes) {
		return false;
	}
}

/**
 * Create a stand-in wmic.
 *
 * @param array<int, string> $lines stdout lines
 */
function wmi_stderrtest_wmic(array $lines = [], int $status = 0, string $stderr = '', int $sleep = 0): string {
	$dir = sys_get_temp_dir() . '/wmi-stderrtest-' . bin2hex(random_bytes(6));

	mkdir($dir, 0700);
	file_put_contents($dir . '/out', $lines === [] ? '' : implode("\n", $lines) . "\n");
	file_put_contents($dir . '/stderr', $stderr);
	file_put_contents($dir . '/wmic', "#!/bin/sh\nD=\"\$(dirname \"\$0\")\"\n" .
		"if [ -s \"\$D/stderr\" ]; then cat \"\$D/stderr\" >&2; fi\n" .
		($sleep > 0 ? "exec sleep " . $sleep . "\n" : '') .
		"cat \"\$D/out\"\nexit " . $status . "\n");
	chmod($dir . '/wmic', 0700);

	return $dir . '/wmic';
}

function wmi_stderrtest_request(string $binary): Wmi_Request {
	return new Wmi_Request('host.example.com', 'u', 'p', 'root\\CIMV2', 'SELECT 1', '|+|', $binary);
}

afterAll(function () {
	foreach (glob(sys_get_temp_dir() . '/wmi-stderrtest-*') ?: [] as $dir) {
		array_map('unlink', glob($dir . '/*') ?: []);
		rmdir($dir);
	}
});

it('keeps stderr out of the result', function () {
	$t = new Wmic_Shell_Transport();

	expect($t->query(wmi_stderrtest_request(wmi_stderrtest_wmic(['CLASS', 'Name', 'A'], 0, "warning: slow\n"))))->toBe(['CLASS', 'Name', 'A']);
	expect($t->error())->toBeNull();
});

it('adds stderr to the error text when wmic fails', function () {
	$t = new Wmic_Shell_Transport();

	expect($t->query(wmi_stderrtest_request(wmi_stderrtest_wmic(['NTSTATUS: denied'], 1, "ERROR: Login to remote object.\n"))))->toBeFalse();
	expect($t->error())->toBe("ERROR: NTSTATUS: denied\nERROR: Login to remote object.");
});

it('uses stderr as the whole detail when stdout is empty, and keeps the old text without it', function () {
	$t = new Wmic_Shell_Transport();

	$t->query(wmi_stderrtest_request(wmi_stderrtest_wmic([], 1, "ERROR: Login to remote object.\n")));

	expect($t->error())->toBe('ERROR: ERROR: Login to remote object.');

	$t->query(wmi_stderrtest_request(wmi_stderrtest_wmic([], 3)));

	expect($t->error())->toBe('ERROR: ');
});

it('reports no data, with stderr when there is some', function () {
	$t = new Wmic_Shell_Transport();

	expect($t->query(wmi_stderrtest_request(wmi_stderrtest_wmic([], 0))))->toBeFalse();
	expect($t->error())->toBe('ERROR: WMI Returned no Data');

	$t->query(wmi_stderrtest_request(wmi_stderrtest_wmic([], 0, "note\n")));

	expect($t->error())->toBe("ERROR: WMI Returned no Data\nnote");
});

it('caps a long stderr in the error text', function () {
	$t = new Wmic_Shell_Transport();

	$t->query(wmi_stderrtest_request(wmi_stderrtest_wmic(['x'], 1, str_repeat('e', 5000))));

	expect(strlen($t->error()))->toBe(strlen("ERROR: x\n") + 2000);
});

it('stops a wmic that does not finish within the timeout', function () {
	$t     = new Wmic_Shell_Transport(1);
	$began = microtime(true);

	expect($t->query(wmi_stderrtest_request(wmi_stderrtest_wmic([], 0, "still connecting\n", 10))))->toBeFalse();
	expect(microtime(true) - $began)->toBeLessThan(6.0);
	expect($t->error())->toBe("ERROR: wmic did not finish within 1 seconds\nstill connecting");
});

it('keeps blank lines inside the output, trims line ends and drops only the final line break', function () {
	$t = new Wmic_Shell_Transport();

	expect($t->query(wmi_stderrtest_request(wmi_stderrtest_wmic(['CLASS', '', 'A  ']))))->toBe(['CLASS', '', 'A']);
});

it('reports a wmic that cannot be started', function () {
	$t = new Wmi_Test_Unstartable_Transport();

	expect($t->query(wmi_stderrtest_request('/usr/bin/wmic')))->toBeFalse();
	expect($t->error())->toBe('ERROR: Unable to start /usr/bin/wmic');
});

it('fails with the shell message when the executable does not exist', function () {
	$t = new Wmic_Shell_Transport();

	expect($t->query(wmi_stderrtest_request('/nonexistent/wmic-for-tests')))->toBeFalse();
	expect($t->error())->toStartWith('ERROR: ');
	expect($t->error())->toContain('wmic-for-tests');
});
