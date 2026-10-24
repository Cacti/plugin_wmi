<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The script-server resource must point at a script that exists, the scripts
 * must load the Cacti files they need from where those files are, and the
 * wmic client path is a registered setting.
 */

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($string, $quote = true) {
		return escapeshellarg($string);
	}
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../setup.php';

const WMI_SS_PLUGIN = __DIR__ . '/../..';

it('points the shipped Exchange resource at a script that exists', function () {
	$xml = (string) file_get_contents(WMI_SS_PLUGIN . '/templates/resource/script_server/exchange_messages.xml');

	expect(preg_match('#<script_path>\|path_cacti\|/plugins/wmi/([^<]+)</script_path>#', $xml, $m))->toBe(1);
	expect(is_file(WMI_SS_PLUGIN . '/' . $m[1]))->toBeTrue();
});

it('exports a resource that points at a script that exists', function () {
	$GLOBALS['__test_fetch_row_map'] = array('wmi_wql_queries' => array('id' => 3, 'name' => 'svc', 'primary_key' => 'Name'));

	$xml = plugin_wmi_create_resource_xml(3);

	$GLOBALS['__test_fetch_row_map'] = array();

	expect(preg_match('#<script_path>\|path_cacti\|/plugins/wmi/([^<]+)</script_path>#', $xml, $m))->toBe(1);
	expect(is_file(WMI_SS_PLUGIN . '/' . $m[1]))->toBeTrue();
	expect($xml)->toContain('<script_function>wmi_script</script_function>');
});

it('loads Cacti files the scripts need from where they are', function (string $script) {
	$source = (string) file_get_contents(WMI_SS_PLUGIN . '/' . $script);

	expect(preg_match_all("#require(?:_once)?\(__DIR__ \. '([^']+)'\)#", $source, $m))->toBeGreaterThan(0);

	foreach ($m[1] as $relative) {
		expect(is_file(dirname(WMI_SS_PLUGIN . '/' . $script) . $relative))->toBeTrue();
	}

	expect($source)->not->toContain('global.php');
	expect($source)->not->toContain('/plugins/wmi/wmic');
})->with(['wmi_script.php', 'script/wmi-script.php']);

it('registers the wmic path as a setting with a default', function () {
	global $settings, $tabs;

	$settings = array();
	$tabs     = array();

	wmi_config_settings();

	expect($settings['path']['path_wmi']['default'])->toBe('/usr/bin/wmic');
	expect($settings['path']['path_wmi']['method'])->toBe('filepath');
});

it('keeps the settings that are already on the path tab', function () {
	global $settings, $tabs;

	$settings = array('path' => array('path_rrdtool' => array('default' => '/usr/bin/rrdtool')));
	$tabs     = array();

	wmi_config_settings();

	expect(array_keys($settings['path']))->toBe(array('path_rrdtool', 'path_wmi'));
});

/**
 * Run the script-server function against a stand-in wmic.
 *
 * @param array<string, string> $config
 */
function wmi_ss_run(array $config, string $command, string $arg1, string $arg2): string {
	$called_by_script_server = true;

	require_once WMI_SS_PLUGIN . '/script/wmi-script.php';

	$w = new Linux_WMI();

	$GLOBALS['__test_config']        = $config;
	$GLOBALS['__test_fetch_row_map'] = array(
		'wmi_user_accounts' => array('username' => 'svc', 'password' => $w->encode('pw')),
		'wmi_wql_queries'   => array('query' => 'SELECT Name,State FROM Win32_Service', 'primary_key' => 'Name', 'namespace' => 'root\\CIMV2'),
	);

	ob_start();

	try {
		$returned = wmi_script('winsrv', 5, 'svc', $command, $arg1, $arg2);
	} finally {
		$output = ob_get_clean();
	}

	$GLOBALS['__test_config']        = array();
	$GLOBALS['__test_fetch_row_map'] = array();

	return $output . (is_string($returned) ? $returned : '');
}

function wmi_ss_wmic(): string {
	$dir = sys_get_temp_dir() . '/wmi-ss-' . bin2hex(random_bytes(6));

	mkdir($dir, 0700);
	file_put_contents($dir . '/wmic', "#!/bin/sh\nprintf '%s\\n' 'CLASS: Win32_Service' 'Name|+|State' 'Spooler|+|Running' 'Dhcp|+|Stopped'\n");
	chmod($dir . '/wmic', 0700);

	return $dir . '/wmic';
}

it('runs the script-server query through the wmic set in the setting', function () {
	$wmic = wmi_ss_wmic();

	expect(wmi_ss_run(array('path_wmi' => $wmic), 'get', 'State', 'Spooler'))->toContain('Running');
	expect(wmi_ss_run(array('path_wmi' => $wmic), 'query', 'index', ''))->toContain('Spooler!Spooler');
	expect(wmi_ss_run(array('path_wmi' => $wmic), 'query', 'State', 'Dhcp'))->toContain("State!Stopped");
	expect(wmi_ss_run(array('path_wmi' => $wmic), 'index', '', ''))->toContain('Spooler');

	unlink($wmic);
	rmdir(dirname($wmic));
});

it('falls back to /usr/bin/wmic when the setting is empty', function () {
	if (is_file('/usr/bin/wmic')) {
		$this->markTestSkipped('A real wmic is installed.');
	}

	expect(wmi_ss_run(array(), 'get', 'State', 'Spooler'))->toBe('');
});
