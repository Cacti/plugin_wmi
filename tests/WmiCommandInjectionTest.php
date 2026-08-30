<?php
/*
 * Regression: the WMI collector built its wmic command line with the device
 * hostname and query namespace unescaped, so a device-supplied hostname could
 * inject a command that runs on the Cacti server. clean() now escapes both and
 * strips cmd.exe metacharacters on Windows.
 *
 * Standalone (the plugin has no test harness): stub the core escaper and $config,
 * include the collector, and assert getcommand() neutralises an injected value.
 */

$GLOBALS['config'] = array('cacti_server_os' => 'unix');

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($s) { return escapeshellarg($s); }
}

require_once __DIR__ . '/../linux_wmi.php';

$fail = 0;

function check($cond, $msg) {
	global $fail;
	if ($cond) {
		print "  ok: $msg\n";
	} else {
		print "  FAIL: $msg\n";
		$fail = 1;
	}
}

/* unix: an injected hostname must be single-quote contained, not break out */
$w = new Linux_WMI();
$w->username    = 'u';
$w->password    = 'p';
$w->binary      = '/usr/bin/wmic';
$w->command     = 'SELECT Name FROM Win32_OperatingSystem';
$w->hostname    = '127.0.0.1; touch /tmp/pwned #';
$w->querynspace = "root\\CIMV2'; id #";

$cmd = $w->getcommand();

check(strpos($cmd, '; touch /tmp/pwned') === false || strpos($cmd, "'127.0.0.1; touch /tmp/pwned #'") !== false,
	'injected hostname is contained inside a quoted argument');
check(preg_match('#//\x27#', $cmd) === 1, 'the target host is quoted (//\'...\')');
check(strpos($cmd, "--namespace='") !== false, 'namespace is quoted');

/* win32: metacharacters are stripped from the hostname before quoting */
$GLOBALS['config']['cacti_server_os'] = 'win32';
$w2 = new Linux_WMI();
$w2->username = 'u'; $w2->password = 'p'; $w2->binary = 'wmic'; $w2->command = 'x';
$w2->hostname = 'host" & calc.exe & %USERNAME%';
$cmd2 = $w2->getcommand();
foreach (array('"', '&', '(', ')', '%') as $meta) {
	check(strpos(substr($cmd2, strpos($cmd2, '//')), $meta) === false, "win32: '$meta' stripped from the target host");
}

exit($fail);
