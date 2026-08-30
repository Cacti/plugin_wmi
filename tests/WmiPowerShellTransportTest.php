<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Standalone checks for PowerShellCim_Transport. The plugin has no test    |
 | harness, so this runs directly: `php tests/WmiPowerShellTransportTest.php`|
 | and exits non-zero on the first failure.                                  |
 +-------------------------------------------------------------------------+
*/

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($s) {
		return escapeshellarg($s);
	}
}

require __DIR__ . '/../linux_wmi.php';

$failures = 0;

function check(string $label, bool $ok): void {
	global $failures;

	print ($ok ? '  ok: ' : '  FAIL: ') . $label . PHP_EOL;

	if (!$ok) {
		$failures++;
	}
}

// A runner that records what it was handed and returns canned CIM output.
function recording_runner(array &$seen, array $stdout, int $exit = 0, string $stderr = ''): callable {
	return function (string $bin, array $args, string $stdin, array $env) use (&$seen, $stdout, $exit, $stderr): array {
		$seen = compact('bin', 'args', 'stdin', 'env');

		return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
	};
}

$secret  = 'P@ss w0rd!&|';
$request = new Wmi_Request('winsrv', 'dom\\adm', $secret, 'root\\CIMV2', 'SELECT Name,State FROM Win32_Service', '|+|', 'pwsh');

// 1. The credential never reaches the command line or the script text.
$seen = [];
$t    = new PowerShellCim_Transport('pwsh', recording_runner($seen, ['Win32_Service', 'Name|+|State', 'Spooler|+|Running']));
$t->query($request);

check('password is not in argv', !str_contains(implode(' ', $seen['args']), $secret));
check('password is not in the powershell script', !str_contains($seen['stdin'], $secret));
check('password is passed through the environment', ($seen['env']['WMI_PASS'] ?? null) === $secret);
check('argv is only the fixed powershell flags', $seen['args'] === ['-NoProfile', '-NonInteractive', '-Command', '-']);
check('the script is fixed text, not built from the request', !str_contains($seen['stdin'], 'winsrv') && !str_contains($seen['stdin'], 'Win32_Service'));

// 2. Output maps onto the class / header / rows contract the parser expects.
$seen           = [];
$t              = new PowerShellCim_Transport('pwsh', recording_runner($seen, ['Win32_Service', 'Name|+|State', 'Spooler|+|Running', 'W32Time|+|Stopped']));
$w              = new Linux_WMI('', $t);
$w->username    = 'dom\\adm';
$w->password    = $secret;
$w->hostname    = 'winsrv';
$w->querynspace = 'root\\CIMV2';
$w->command     = 'SELECT Name,State FROM Win32_Service';
$w->indexkey    = 'Name';
$w->fetch();

check('class name is parsed', $w->fetch_class() === 'Win32_Service');
check('column header is parsed', $w->fetch_indexes() === ['Name', 'State']);
check('a row value can be looked up by key', $w->fetch_value('State', 'W32Time') === 'Stopped');

// 3. An empty result and a non-zero exit both surface as errors.
$seen = [];
$t    = new PowerShellCim_Transport('pwsh', recording_runner($seen, []));
check('empty output is an error', $t->query($request) === false && str_contains((string) $t->error(), 'no Data'));

$seen = [];
$t    = new PowerShellCim_Transport('pwsh', recording_runner($seen, [], 1, 'Access denied'));
check('non-zero exit surfaces stderr', $t->query($request) === false && str_contains((string) $t->error(), 'Access denied'));

// 4. Transport auto-selection follows the Cacti server OS.
$GLOBALS['config'] = ['cacti_server_os' => 'win32'];
check('win32 server defaults to the PowerShell transport', (new Linux_WMI())->getcommand() === false);

$GLOBALS['config'] = ['cacti_server_os' => 'unix'];
check('other servers default to the wmic transport', is_string((new Linux_WMI())->getcommand()));

if ($failures > 0) {
	print PHP_EOL . "$failures check(s) failed" . PHP_EOL;
	exit(1);
}

print PHP_EOL . 'all checks passed' . PHP_EOL;
exit(0);
