<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The remove-query confirmation dialog is printed by a hook that also calls
 * exit, so its form action is checked in the source.
 */

it('builds the confirmation form action with an id parameter', function () {
	$source = plugin_test_read_source('setup.php');

	expect($source)->toContain("form_start('host_templates.php?action=edit&id=' . get_request_var('host_template_id'))");
	expect($source)->not->toContain("action=edit&id' .");
});
