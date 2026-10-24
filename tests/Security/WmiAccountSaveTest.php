<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * wmi_accounts.php requires auth.php at load time, so these checks read its
 * source: sql_save() must be reached only after the submitted account has
 * been validated.
 */

it('saves an account only after its input is validated', function () {
	$source = preg_replace('/\s+/', ' ', plugin_test_read_source('wmi_accounts.php'));

	expect($source)->toContain('wmi_account_input_valid(');
	expect($source)->toContain('wmi_account_password_fits(');
	expect($source)->toContain("if (\$valid) { \$id = sql_save(\$save, 'wmi_user_accounts', 'id'); } else { raise_message(4);");
});
