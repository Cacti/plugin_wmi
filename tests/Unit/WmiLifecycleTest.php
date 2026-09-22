<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for plugin_wmi_version() and the plugin lifecycle
 * contract wrappers (plugin_wmi_check_config/upgrade,
 * plugin_wmi_setup_tables) in setup.php.
 *
 * plugin_wmi_uninstall() is intentionally NOT covered here: it
 * unconditionally include_once()s Cacti core's real lib/api_data_source.php
 * and lib/api_graph.php, which is unsafe to load against this suite's
 * stubs (no real Cacti checkout available).
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

beforeEach(function () {
	$GLOBALS['__test_db_calls']         = array();
	$GLOBALS['__test_add_column_calls'] = array();
	$GLOBALS['__test_sql_save_calls']   = array();
});

it('parses the plugin INFO file into an info array', function () {
	$info = plugin_wmi_version();

	expect($info)->toBeArray();
	expect($info)->toHaveKey('name');
	expect($info)->toHaveKey('version');
	expect($info['name'])->toBe('wmi');
});

it('reports the config as always valid', function () {
	expect(plugin_wmi_check_config())->toBeTrue();
});

it('reports the upgrade as always successful', function () {
	expect(plugin_wmi_upgrade())->toBeTrue();
});

it('adds the wmi_account column and creates every table', function () {
	plugin_wmi_setup_tables();

	expect($GLOBALS['__test_add_column_calls'])->toHaveCount(1);
	expect($GLOBALS['__test_add_column_calls'][0]['plugin'])->toBe('wmi');
	expect($GLOBALS['__test_add_column_calls'][0]['table'])->toBe('host');
	expect($GLOBALS['__test_add_column_calls'][0]['data']['name'])->toBe('wmi_account');

	$creates = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute' && stripos($call['sql'], 'CREATE TABLE IF NOT EXISTS') !== false;
	});

	expect($creates)->toHaveCount(7);
});

it('seeds both WMI data_input rows when they are not already present', function () {
	plugin_wmi_setup_tables();

	expect($GLOBALS['__test_sql_save_calls'])->toHaveCount(2);
	expect($GLOBALS['__test_sql_save_calls'][0]['table'])->toBe('data_input');
	expect($GLOBALS['__test_sql_save_calls'][0]['array']['hash'])->toBe('4af550dfe8b451579054d038ad62ba3e');
	expect($GLOBALS['__test_sql_save_calls'][1]['array']['hash'])->toBe('42e584b81075f6ad6556e62afc509179');

	$inserts = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute' && stripos($call['sql'], 'INSERT INTO `data_input_fields`') !== false;
	});

	// 2 fields for the non-indexed query, 4 for the indexed query.
	expect($inserts)->toHaveCount(6);
});
