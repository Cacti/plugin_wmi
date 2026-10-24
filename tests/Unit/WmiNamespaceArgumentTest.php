<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The wmic namespace argument is single-quoted, so a namespace saved with
 * doubled backslashes must reach wmic with one.
 */

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($string, $quote = true) {
		return escapeshellarg($string);
	}
}

require_once __DIR__ . '/../../includes/linux_wmi.php';

function wmi_test_namespace_command(string $namespace): string {
	$w              = new Linux_WMI('', new Wmic_Shell_Transport());
	$w->username    = 'u';
	$w->password    = 'p';
	$w->hostname    = 'host';
	$w->command     = 'SELECT 1';
	$w->querynspace = $namespace;

	return (string) $w->getcommand();
}

it('passes a doubled-backslash namespace to wmic with a single backslash', function () {
	expect(wmi_test_namespace_command('root\\\\CIMV2'))->toContain("--namespace='root\\CIMV2'");
});

it('leaves a single-backslash namespace unchanged', function () {
	expect(wmi_test_namespace_command('root\\CIMV2'))->toContain("--namespace='root\\CIMV2'");
});

it('omits the namespace argument when none is set', function () {
	expect(wmi_test_namespace_command(''))->not->toContain('--namespace');
});
