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

require_once __DIR__ . '/../linux_wmi.php';

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

	it('surfaces an empty result as an error', function () use ($secret) {
		$request = wmi_test_request($secret);

		$seen = [];
		$t    = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, []));

		expect($t->query($request))->toBeFalse();
		expect(str_contains((string) $t->error(), 'no Data'))->toBeTrue();
	});

	it('surfaces stderr on a non-zero exit', function () use ($secret) {
		$request = wmi_test_request($secret);

		$seen = [];
		$t    = new PowerShellCim_Transport('pwsh', wmi_test_recording_runner($seen, [], 1, 'Access denied'));

		expect($t->query($request))->toBeFalse();
		expect(str_contains((string) $t->error(), 'Access denied'))->toBeTrue();
	});

	it('follows the Cacti server OS for transport auto-selection', function () {
		$GLOBALS['config']['cacti_server_os'] = 'win32';
		expect((new Linux_WMI())->getcommand())->toBeFalse();

		$GLOBALS['config']['cacti_server_os'] = 'unix';
		expect(is_string((new Linux_WMI())->getcommand()))->toBeTrue();
	});
});
