<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression: the WMI collector built its wmic command line with the device
 * hostname and query namespace unescaped, so a device-supplied hostname could
 * inject a command that runs on the Cacti server. clean() now escapes both and
 * strips cmd.exe metacharacters on Windows.
 */

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($s) {
		return escapeshellarg($s);
	}
}

require_once __DIR__ . '/../linux_wmi.php';

describe('WMI command injection hardening', function () {
	it('contains an injected unix hostname inside a quoted argument', function () {
		global $config;
		$config['cacti_server_os'] = 'unix';

		$w             = new Linux_WMI();
		$w->username   = 'u';
		$w->password   = 'p';
		$w->binary     = '/usr/bin/wmic';
		$w->command    = 'SELECT Name FROM Win32_OperatingSystem';
		$w->hostname   = '127.0.0.1; touch /tmp/pwned #';
		$w->querynspace = "root\\CIMV2'; id #";

		$cmd = $w->getcommand();

		expect(strpos($cmd, '; touch /tmp/pwned') === false || strpos($cmd, "'127.0.0.1; touch /tmp/pwned #'") !== false)->toBeTrue();
		expect(preg_match('#//\x27#', $cmd))->toBe(1);
		expect($cmd)->toContain("--namespace='");
	});

	it('strips shell/cmd.exe metacharacters from a win32 hostname before quoting', function () {
		global $config;
		$config['cacti_server_os'] = 'win32';

		$w           = new Linux_WMI();
		$w->username = 'u';
		$w->password = 'p';
		$w->binary   = 'wmic';
		$w->command  = 'x';
		$w->hostname = 'host" & calc.exe & %USERNAME%';

		$cmd = $w->getcommand();

		foreach (['"', '&', '(', ')', '%'] as $meta) {
			expect(strpos(substr($cmd, strpos($cmd, '//')), $meta))->toBeFalse();
		}

		$config['cacti_server_os'] = 'unix';
	});
});
