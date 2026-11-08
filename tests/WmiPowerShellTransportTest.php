<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Checks for PowerShellCim_Transport: credentials/queries never reach argv or
 * the script text, output maps onto the class/header/rows contract the
 * parser expects, and both an empty result and a non-zero exit surface as
 * errors.
 */

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($s) {
		return escapeshellarg($s);
	}
}

require_once __DIR__ . '/../includes/linux_wmi.php';

/** A runner that records what it was handed and returns canned CIM output. */
function wmi_test_recording_runner(array &$seen, array $stdout, int $exit = 0, string $stderr = ''): callable {
	return function (string $bin, array $args, string $stdin, array $env) use (&$seen, $stdout, $exit, $stderr): array {
		$seen = compact('bin', 'args', 'stdin', 'env');

		return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
	};
}

function wmi_test_request(string $secret): Wmi_Request {
	return new Wmi_Request('winsrv', 'dom\\adm', $secret, 'root\\CIMV2', 'SELECT Name,State FROM Win32_Service', '|+|', 'pwsh');
}

describe('PowerShellCim_Transport', function () {
	$secret = 'P@ss w0rd!&|';

	it('never puts the credential on the command line or in the script text', function () use ($secret) {
		$request = wmi_test_request($secret);

		$seen = [];
		$t    = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, ['Win32_Service', 'Name|+|State', 'Spooler|+|Running']));
		$t->query($request);

		expect(str_contains(implode(' ', $seen['args']), $secret))->toBeFalse();
		expect(str_contains($seen['stdin'], $secret))->toBeFalse();
		expect($seen['env']['WMI_PASS'] ?? null)->toBe($secret);
		expect($seen['args'])->toBe(['-NoProfile', '-NonInteractive', '-Command', '-']);
		expect(str_contains($seen['stdin'], 'winsrv'))->toBeFalse();
		expect(str_contains($seen['stdin'], 'Win32_Service'))->toBeFalse();
	});

	it('maps output onto the class / header / rows contract the parser expects', function () use ($secret) {
		$seen           = [];
		$t              = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, ['Win32_Service', 'Name|+|State', 'Spooler|+|Running', 'W32Time|+|Stopped']));
		$w              = new Linux_WMI('', $t);
		$w->username    = 'dom\\adm';
		$w->password    = $secret;
		$w->hostname    = 'winsrv';
		$w->querynspace = 'root\\CIMV2';
		$w->command     = 'SELECT Name,State FROM Win32_Service';
		$w->indexkey    = 'Name';
		$w->fetch();

		expect($w->fetch_class())->toBe('Win32_Service');
		expect($w->fetch_indexes())->toBe(['Name', 'State']);
		expect($w->fetch_value('State', 'W32Time'))->toBe('Stopped');
	});

	it('returns a successful empty result without manufacturing a transport error', function () use ($secret) {
		$request = wmi_test_request($secret);

		$seen = [];
		$t    = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, []));

		expect($t->query($request))->toBe([]);
		expect($t->error())->toBeNull();
	});

	it('passes configured WinRM settings to the CIM session', function () use ($secret) {
		$seen = [];
		$t = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, []));
		$request = new Wmi_Request('winsrv', 'dom\\adm', $secret, 'root\\CIMV2', 'SELECT Name FROM Win32_Service', '|+|', 'pwsh', true, 5987, 'Kerberos');

		expect($t->query($request))->toBe([]);
		expect($seen['env']['WMI_SSL'])->toBe('1');
		expect($seen['env']['WMI_PORT'])->toBe('5987');
		expect($seen['env']['WMI_AUTH'])->toBe('Kerberos');
		expect($seen['stdin'])->toContain('New-CimSessionOption -Protocol Wsman -UseSsl');
		expect($seen['stdin'])->toContain("\$sessionParams['Port']");
	});

	it('collects native PDH values over WinRM and returns formatted samples', function () use ($secret) {
		$seen = [];
		$lines = ['WindowsPerformanceCounters', 'Path|+|Instance|+|Timestamp|+|CookedValue', '\\Process V2(windows pid 42)\\% Processor Time|+|windows pid 42|+|2026-11-08T10:00:00|+|12.5'];
		$t = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, $lines));
		$request = new Wmi_Request('winsrv', 'dom\\adm', $secret, 'PDH', "\\Process V2(*)\\% Processor Time", '|+|', 'pwsh', true, 5986, 'Kerberos', true);

		expect($t->query($request))->toBe($lines);
		expect($seen['env']['WMI_COUNTER'])->toBe('1');
		expect($seen['stdin'])->toContain('Get-Counter -Counter $CounterPaths -MaxSamples 2 -SampleInterval 1');
		expect($seen['stdin'])->toContain("[cultureinfo]::InvariantCulture");
		expect($seen['stdin'])->toContain('Invoke-Command @invoke');
	});

	it('reads the WinRM settings when Linux_WMI builds a production request', function () use ($secret) {
		$seen = [];
		$GLOBALS['__test_config'] = [
			'wmi_winrm_ssl'  => 'on',
			'wmi_winrm_port' => '5987',
			'wmi_winrm_auth' => 'Kerberos',
		];
		$t = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, ['Win32_Service', 'Name', 'Spooler']));
		$w = new Linux_WMI('', $t);
		$w->hostname = 'winsrv';
		$w->username = 'dom\\adm';
		$w->password = $secret;
		$w->querynspace = 'root\\CIMV2';
		$w->command = 'SELECT Name FROM Win32_Service';
		$w->fetch();
		$GLOBALS['__test_config'] = [];

		expect($seen['env']['WMI_SSL'])->toBe('1');
		expect($seen['env']['WMI_PORT'])->toBe('5987');
		expect($seen['env']['WMI_AUTH'])->toBe('Kerberos');
	});

	it('rejects Basic authentication without HTTPS before starting PowerShell', function () use ($secret) {
		$seen = [];
		$t = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, []));
		$request = new Wmi_Request('winsrv', 'dom\\adm', $secret, 'root\\CIMV2', 'SELECT Name FROM Win32_Service', '|+|', 'pwsh', false, null, 'Basic');

		expect($t->query($request))->toBeFalse();
		expect($t->error())->toContain('requires HTTPS');
		expect($seen)->toBe([]);
	});

	it('rejects unsupported authentication methods before starting PowerShell', function () use ($secret) {
		$seen = [];
		$t = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, []));
		$request = new Wmi_Request('winsrv', 'dom\\adm', $secret, 'root\\CIMV2', 'SELECT Name FROM Win32_Service', '|+|', 'pwsh', false, null, 'NTLM');

		expect($t->query($request))->toBeFalse();
		expect($t->error())->toContain('Invalid WinRM authentication method');
		expect($seen)->toBe([]);
	});

	it('rejects an out-of-range WinRM port before starting PowerShell', function () use ($secret) {
		$seen = [];
		$t = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, []));
		$request = new Wmi_Request('winsrv', 'dom\\adm', $secret, 'root\\CIMV2', 'SELECT Name FROM Win32_Service', '|+|', 'pwsh', false, 65536, 'Negotiate');

		expect($t->query($request))->toBeFalse();
		expect($t->error())->toContain('Invalid WinRM port');
		expect($seen)->toBe([]);
	});

	it('rejects an empty PDH query before starting PowerShell', function () use ($secret) {
		$seen = [];
		$t = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, []));
		$request = new Wmi_Request('winsrv', 'dom\\adm', $secret, 'PDH', " \n ", '|+|', 'pwsh', false, null, 'Negotiate', true);

		expect($t->query($request))->toBeFalse();
		expect($t->error())->toContain('counter path is required');
		expect($seen)->toBe([]);
	});

	it('rejects more than 64 PDH paths before starting PowerShell', function () use ($secret) {
		$seen = [];
		$t = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, []));
		$paths = implode("\n", array_fill(0, 65, '\\Processor(_Total)\\% Processor Time'));
		$request = new Wmi_Request('winsrv', 'dom\\adm', $secret, 'PDH', $paths, '|+|', 'pwsh', false, null, 'Negotiate', true);

		expect($t->query($request))->toBeFalse();
		expect($t->error())->toContain('at most 64 counter paths');
		expect($seen)->toBe([]);
	});

	it('surfaces stderr on a non-zero exit', function () use ($secret) {
		$request = wmi_test_request($secret);

		$seen = [];
		$t    = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, [], 1, 'Access denied'));

		expect($t->query($request))->toBeFalse();
		expect(str_contains((string) $t->error(), 'Access denied'))->toBeTrue();
	});

	it('returns standard output from the default process runner', function () use ($secret) {
		$dir    = sys_get_temp_dir() . '/wmi-powershell-' . bin2hex(random_bytes(6));
		$binary = $dir . '/powershell';

		mkdir($dir, 0700);
		file_put_contents($binary, "#!/bin/sh\nprintf 'Win32_Service\\nName|+|State\\nSpooler|+|Running\\n'\nprintf 'warning on stderr\\n' >&2\n");
		chmod($binary, 0700);

		try {
			$t = new PowerShellCim_Transport($binary, null, 1);

			expect($t->query(wmi_test_request($secret)))->toBe(['Win32_Service', 'Name|+|State', 'Spooler|+|Running']);
			expect($t->error())->toBeNull();
		} finally {
			unlink($binary);
			rmdir($dir);
		}
	});

	it('drains both output streams and stops a stalled PowerShell process', function () use ($secret) {
		$dir    = sys_get_temp_dir() . '/wmi-powershell-' . bin2hex(random_bytes(6));
		$binary = $dir . '/powershell';

		mkdir($dir, 0700);
		file_put_contents($binary, "#!/bin/sh\nhead -c 65536 /dev/zero >&2\nexec sleep 10\n");
		chmod($binary, 0700);

		try {
			$t     = new PowerShellCim_Transport($binary, null, 1);
			$began = microtime(true);

			expect($t->query(wmi_test_request($secret)))->toBeFalse();
			expect(microtime(true) - $began)->toBeLessThan(6.0);
			expect($t->error())->toContain('did not finish within 1 seconds');
		} finally {
			unlink($binary);
			rmdir($dir);
		}
	});

	it('stops PowerShell when its response exceeds the configured size limit', function () use ($secret) {
		$dir = sys_get_temp_dir() . '/wmi-powershell-' . bin2hex(random_bytes(6));
		$binary = $dir . '/powershell';

		mkdir($dir, 0700);
		file_put_contents($binary, "#!/bin/sh\nprintf '12345678901234567890123456789012\\n'\n");
		chmod($binary, 0700);

		try {
			$t = new PowerShellCim_Transport($binary, null, 1, 16);

			expect($t->query(wmi_test_request($secret)))->toBeFalse();
			expect($t->error())->toContain('exceeded the 16 byte limit');
		} finally {
			unlink($binary);
			rmdir($dir);
		}
	});

	it('follows the Cacti server OS for transport auto-selection', function () {
		$GLOBALS['config']['cacti_server_os'] = 'win32';
		expect((new Linux_WMI())->getcommand())->toBeFalse();

		$GLOBALS['config']['cacti_server_os'] = 'unix';
		expect(is_string((new Linux_WMI())->getcommand()))->toBeTrue();
	});

	/*
	 * Get-CimInstance has no -Credential parameter, so passing one alongside
	 * -ComputerName in the splat fails every remote query with an
	 * unknown-parameter error before it reaches the CIM provider. A remote
	 * credential must go through an explicit New-CimSession instead.
	 */
	it('authenticates remote queries through an explicit CimSession rather than -Credential on Get-CimInstance', function () {
		$script = (new ReflectionClassConstant(PowerShellCim_Transport::class, 'SCRIPT'))->getValue();

		expect($script)->toContain('New-CimSession');
		expect($script)->toContain("\$params['CimSession']");
		expect($script)->not->toContain("\$params['Credential']");
		expect($script)->not->toContain("\$params['ComputerName']");
	});
});
