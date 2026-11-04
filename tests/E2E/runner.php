<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Helpers for the end-to-end Pest files: run a driver script in its own
 * process and return what it observed. Only the Pest process loads this
 * file; it defines functions and does nothing when requested over the web.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);

	exit;
}

/**
 * Whether the end-to-end tests can run here, or the reason they cannot.
 *
 * @return string '' when ready, otherwise the reason to skip.
 */
function wmi_e2e_unready(): string {
	$root = dirname(__DIR__, 4);

	if (getenv('WMI_E2E') !== '1') {
		return 'Set WMI_E2E=1 with an installed Cacti and database to run.';
	}

	if (!is_readable($root . '/include/config.php')) {
		return 'No installed Cacti (include/config.php) was found.';
	}

	$log = $root . '/log/cacti.log';

	if (!(is_file($log) ? is_writable($log) : is_writable(dirname($log)))) {
		return 'Cacti log/cacti.log is not writable by the test user; Cacti would refuse to start.';
	}

	return '';
}

/**
 * Run a driver from tests/E2E in a child process and decode its JSON result.
 * The result is cached for the rest of the Pest process.
 *
 * With WMI_E2E_COV set to a directory, every PHP process the driver starts
 * (the driver, the poller master and children, the built-in web server) records
 * which plugin lines ran into <dir>/<driver>/ for tests/bin/e2e-coverage-report.php.
 *
 * @return array<string, mixed>
 */
function wmi_e2e_run(string $driver): array {
	static $cache = [];

	if (isset($cache[$driver])) {
		return $cache[$driver];
	}

	$root = dirname(__DIR__, 4);
	$work = sys_get_temp_dir() . '/wmi-e2e-' . bin2hex(random_bytes(6));
	$env  = 'WMI_E2E=1 WMI_E2E_WORKDIR=' . escapeshellarg($work);

	mkdir($work, 0700);

	$cov = getenv('WMI_E2E_COV');

	if ($cov !== false && $cov !== '' && is_dir($cov)) {
		$dump = rtrim($cov, '/') . '/' . pathinfo($driver, PATHINFO_FILENAME);

		if (!is_dir($dump)) {
			mkdir($dump, 0777, true);
		}

		mkdir($work . '/ini', 0700);
		file_put_contents($work . '/ini/99-wmi-cov.ini', "xdebug.mode=coverage\nauto_prepend_file=" . __DIR__ . "/coverage_prepend.php\n");

		$env .= ' PHP_INI_SCAN_DIR=' . escapeshellarg(':' . $work . '/ini') . ' WMI_COV_DIR=' . escapeshellarg($dump);
	}

	$output = [];
	$status = 0;

	exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $driver) . ' 2>&1', $output, $status);

	wmi_e2e_remove($work);

	$json = json_decode((string) end($output), true);

	$cache[$driver] = is_array($json) ? $json : ['driver_failed' => 'exit status ' . $status . "\n" . implode("\n", $output)];

	return $cache[$driver];
}

/**
 * Remove a work directory this runner created, and nothing else.
 */
function wmi_e2e_remove(string $dir): void {
	$tmp = realpath(sys_get_temp_dir());

	if (strpos(basename($dir), 'wmi-e2e-') !== 0 || !is_dir($dir) || $tmp === false || strpos((string) realpath($dir), $tmp . '/') !== 0) {
		return;
	}

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
		if ($item->isLink() || $item->isFile()) {
			unlink($item->getPathname());
		} else {
			rmdir($item->getPathname());
		}
	}

	rmdir($dir);
}
