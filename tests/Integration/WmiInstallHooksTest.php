<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Integration coverage for plugin_wmi_install(): verifies every hook and
 * the realm the plugin depends on at runtime are actually registered,
 * together with its full table set, in a single end-to-end pass.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

beforeEach(function () {
	$GLOBALS['__test_db_calls']          = array();
	$GLOBALS['__test_registered_hooks']  = array();
	$GLOBALS['__test_registered_realms'] = array();
});

it('registers every hook wmi depends on, its realm, and provisions its tables', function () {
	plugin_wmi_install();

	$hooks = array();
	foreach ($GLOBALS['__test_registered_hooks'] as $registered) {
		$hooks[$registered['hook']] = $registered;
	}

	foreach (array(
		'config_arrays',
		'config_form',
		'config_settings',
		'draw_navigation_text',
		'api_device_save',
		'data_input_sql_where',
		'poller_bottom',
		'device_template_edit',
		'device_template_top',
		'device_edit_pre_bottom',
		'api_device_new',
	) as $expected) {
		expect($hooks)->toHaveKey($expected);
		expect($hooks[$expected]['plugin'])->toBe('wmi');
		expect($hooks[$expected]['file'])->toBe('setup.php');
	}

	expect($GLOBALS['__test_registered_realms'])->toHaveCount(1);
	expect($GLOBALS['__test_registered_realms'][0]['file'])->toBe('wmi_accounts.php,wmi_queries.php,wmi_tools.php');

	$creates = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute' && stripos($call['sql'], 'CREATE TABLE IF NOT EXISTS') !== false;
	});

	expect($creates)->toHaveCount(7);
});
