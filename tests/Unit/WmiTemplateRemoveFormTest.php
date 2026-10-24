<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The remove-query confirmation dialog is printed by a hook that ends in
 * exit, so the form_start() stub throws once it has recorded the action URL.
 */

if (!function_exists('form_start')) {
	function form_start($url, $name = '', $multipart = false) {
		throw new RuntimeException($url);
	}
}

require_once __DIR__ . '/../../setup.php';

it('builds the confirmation form action with an id parameter', function () {
	$GLOBALS['__test_request'] = array('action' => 'item_remove_wq_confirm', 'host_template_id' => '12');

	try {
		wmi_device_template_top();
		$action = '';
	} catch (RuntimeException $e) {
		$action = $e->getMessage();
	} finally {
		$GLOBALS['__test_request'] = array();
	}

	expect($action)->toBe('host_templates.php?action=edit&id=12');
});
