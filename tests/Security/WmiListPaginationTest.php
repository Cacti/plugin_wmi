<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The list pages cannot be executed here (each requires auth.php at load
 * time), so these checks read their source. The pager total must count the
 * filtered table, not the rows already limited to the current page.
 */

describe('list page pager totals', function () {
	it('counts all matching queries rather than the current page', function () {
		$source = plugin_test_read_source('wmi_queries.php');

		expect($source)->not->toContain('$total_rows = sizeof($queries)');
		expect(preg_replace('/\s+/', ' ', $source))->toContain('$total_rows = db_fetch_cell("SELECT COUNT(*) FROM wmi_wql_queries $sql_where");');
	});

	it('counts only the accounts matching the search filter', function () {
		$source = plugin_test_read_source('wmi_accounts.php');

		expect($source)->not->toContain("'SELECT COUNT(*) FROM wmi_user_accounts'");
		expect(preg_replace('/\s+/', ' ', $source))->toContain('$total_rows = db_fetch_cell("SELECT COUNT(*) FROM wmi_user_accounts $sql_where");');
	});
});
