<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the CSP migration of the WMI Accounts and Queries
 * filter "rows" selects. Both entry points are excluded from the patch-
 * coverage gate, so without this source-level assertion CI would not catch a
 * reintroduced inline `onChange='applyFilter()'` handler (which trips Cacti's
 * Content-Security-Policy script-src-attr directive) or a lost ready-block
 * binding.
 */

$filter_files = array('wmi_accounts.php', 'wmi_queries.php');

foreach ($filter_files as $file) {
	describe("csp filter handler migration in {$file}", function () use ($file) {
		$source = plugin_test_read_source($file);

		it('has no inline onChange handler on the rows select', function () use ($source, $file) {
			expect($source)->not->toMatch('/<select\s+id=[\'"]rows[\'"][^>]*\sonChange\s*=/i', "{$file} still has an inline onChange handler on the rows select");
		});

		it('has no inline applyFilter onChange handler anywhere', function () use ($source, $file) {
			expect($source)->not->toMatch('/onChange\s*=\s*[\'"]applyFilter\(/i', "{$file} still contains an inline applyFilter onChange handler");
		});

		it('binds the rows select change event from the ready block', function () use ($source, $file) {
			expect($source)->toMatch('/\$\(\s*[\'"]#rows[\'"]\s*\)\s*\.change\s*\(/', "{$file} does not bind #rows change() from the ready block");
		});
	});
}
