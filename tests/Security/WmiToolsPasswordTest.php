<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * wmi_tools.php chdir()s and requires auth.php at load time, so it cannot be
 * executed here; these checks read its source. The password typed into the
 * query tool must not be kept in the session filter store or written back
 * into the page.
 */

describe('wmi_tools.php password handling', function () {
	$source = plugin_test_read_source('wmi_tools.php');

	it('does not keep the password in the session filter store', function () use ($source) {
		$start = strpos($source, 'function process_request_vars');
		$end   = strpos($source, 'validate_store_request_vars', $start);
		$block = substr($source, $start, $end - $start);

		expect($block)->toContain("'username' =>");
		expect($block)->not->toContain("'password'");
	});

	it('does not write the password back into the form', function () use ($source) {
		expect($source)->not->toContain("html_escape_request_var('password')");
		expect($source)->toContain("id='password' value=''");
	});

	it('still reads the submitted password for the query itself', function () use ($source) {
		expect($source)->toContain("get_nfilter_request_var('password')");
	});
});
