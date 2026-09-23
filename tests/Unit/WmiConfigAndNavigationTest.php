<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for wmi_config_arrays(), wmi_data_input_sql_where(),
 * wmi_draw_navigation_text(), and wmi_poller_bottom() in setup.php.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

beforeEach(function () {
	$GLOBALS['__test_db_calls']                 = array();
	$GLOBALS['__test_auth_augment_roles_calls'] = array();
});

it('registers the WMI data input types, menu entries, and template-editor role', function () {
	global $menu, $input_types, $fields_data_query_edit, $wmi_frequencies;

	$menu                   = array(__('Utilities') => array(), __('Data Collection') => array());
	$input_types            = array();
	$fields_data_query_edit = array();

	wmi_config_arrays();

	expect($input_types)->toHaveCount(2);
	expect($menu[__('Utilities')])->toHaveKey('plugins/wmi/wmi_tools.php');
	expect($menu[__('Data Collection')])->toHaveKey('plugins/wmi/wmi_queries.php');
	expect($wmi_frequencies)->toHaveKey('86400');
	expect($GLOBALS['__test_auth_augment_roles_calls'])->toHaveCount(1);
	expect($GLOBALS['__test_auth_augment_roles_calls'][0]['files'])->toBe(['wmi_queries.php']);
});

it('excludes the special WMI data input methods from the sql_where clause', function () {
	expect(wmi_data_input_sql_where(''))->toContain('WHERE (di.hash NOT IN');
	expect(wmi_data_input_sql_where('di.enabled = 1'))->toContain('di.enabled = 1 AND (di.hash NOT IN');
});

it('adds the wmi breadcrumb entries without disturbing existing ones', function () {
	$nav = wmi_draw_navigation_text(array('other.php:' => array('title' => 'Other')));

	expect($nav)->toHaveKey('other.php:');
	expect($nav)->toHaveKey('wmi_accounts.php:');
});

it('dispatches the background poller only on poller_id 1', function () {
	$GLOBALS['config']['poller_id'] = 1;

	wmi_poller_bottom();

	$execCalls = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'exec_background';
	});

	expect($execCalls)->toHaveCount(1);
});

it('does not dispatch the background poller on any other poller_id', function () {
	$GLOBALS['config']['poller_id'] = 2;

	wmi_poller_bottom();

	$execCalls = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'exec_background';
	});

	expect($execCalls)->toBeEmpty();
});
