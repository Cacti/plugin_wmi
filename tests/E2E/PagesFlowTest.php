<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * End-to-end page flows: real HTTP requests to PHP's built-in server serving
 * a real Cacti installation and database, logged in as real users, with a
 * fake wmic behind the Query Tool. It runs only when WMI_E2E=1 and an
 * installed Cacti is present.
 */

require_once __DIR__ . '/runner.php';

/** @return array<string, mixed> */
function wmi_e2e_pages(): array {
	return wmi_e2e_run('pages_flow.php');
}

describe('WMI pages through real requests', function () {
	beforeEach(function () {
		$reason = wmi_e2e_unready();

		if ($reason !== '') {
			$this->markTestSkipped($reason);
		}

		if (!function_exists('curl_init')) {
			$this->markTestSkipped('The curl extension is needed for the page flows.');
		}
	});

	it('runs the driver to completion and logs in the test users', function () {
		$o = wmi_e2e_pages();

		expect($o['driver_failed'] ?? '')->toBe('');
		expect($o['realm_found'])->toBeTrue();
		expect($o['admin_login'])->toBeTrue();
		expect($o['nowmi_login'])->toBeTrue();
		expect($o['bad_login'])->toBeFalse();
	});

	it('serves the login to anonymous users and refuses users without the WMI realm', function (string $page) {
		$o = wmi_e2e_pages()['access_' . $page];

		expect($o['anon_login_form'])->toBeTrue();
		expect($o['anon_sees_page'])->toBeFalse();
		expect($o['nowmi_denied'])->toBeTrue();
		expect($o['admin_code'])->toBe(200);
	})->with(['accounts', 'queries', 'tools']);

	it('creates an account and stores a password that decodes', function () {
		$o = wmi_e2e_pages()['acct_create'];

		expect($o['count'])->toBe(1);
		expect($o['username'])->toBe('DOM\\svc');
		expect($o['password'])->toBe('pw-one');
	});

	it('rejects an account save with bad input and writes nothing', function (string $case) {
		expect(wmi_e2e_pages()['acct_reject_' . $case]['count'])->toBe(0);
	})->with(['mismatch', 'empty_password', 'empty_name', 'empty_username', 'too_long']);

	it('accepts a 64 character password and stores it within the column', function () {
		$o = wmi_e2e_pages()['acct_64'];

		expect($o['count'])->toBe(1);
		expect($o['password_ok'])->toBeTrue();
		expect($o['stored_length'])->toBeLessThanOrEqual(256);
	});

	it('keeps the stored password when an edit leaves it blank, replaces it when confirmed and ignores a mismatch', function () {
		$o = wmi_e2e_pages();

		expect($o['acct_edit_blank'])->toBe(['renamed' => 1, 'password' => 'pw-one']);
		expect($o['acct_edit_new_password']['password'])->toBe('pw-two');
		expect($o['acct_edit_mismatch'])->toBe(['password' => 'pw-two', 'name' => 'e2e-acct-1b']);
	});

	it('writes nothing without a CSRF token or by GET', function () {
		$o = wmi_e2e_pages();

		expect($o['acct_no_csrf']['count'])->toBe(0);
		expect($o['acct_get_save']['count'])->toBe(0);
		expect($o['query_no_csrf']['count'])->toBe(0);
		expect($o['query_get_save']['count'])->toBe(0);
	});

	it('counts only the accounts matching the search in the pager', function () {
		$o = wmi_e2e_pages()['acct_list_filtered'];

		expect($o['matching'])->toBeLessThan($o['total']);
		expect($o['nav'])->toBe($o['matching']);
		expect($o['has_x1'])->toBeTrue();
		expect($o['has_other'])->toBeFalse();
	});

	it('lists the selected accounts in the delete confirmation and then deletes them', function () {
		$o = wmi_e2e_pages();

		expect($o['acct_delete_confirm']['lists_names'])->toBeTrue();
		expect($o['acct_delete']['left'])->toBe(0);
	});

	it('creates and edits a query and keeps its hash', function () {
		$o = wmi_e2e_pages();

		expect($o['query_create']['row'])->toBe(['name' => 'e2eq-new', 'frequency' => 300, 'enabled' => 'on', 'namespace' => 'root\\\\CIMV2', 'query' => 'SELECT Name FROM Win32_Service', 'primary_key' => 'Name']);
		expect($o['query_create']['hash_len'])->toBe(32);
		expect($o['query_edit']['frequency'])->toBe(600);
		expect($o['query_edit']['same_hash'])->toBeTrue();
	});

	it('counts all matching queries across pages in the pager', function () {
		$o = wmi_e2e_pages()['query_pager'];

		expect($o['total_matching'])->toBe(7);
		expect($o['p1']['nav'])->toBe(7);
		expect($o['p1']['rows_shown'])->toBe(3);
		expect($o['p3']['nav'])->toBe(7);
		expect($o['p3']['rows_shown'])->toBe(1);
		expect($o['one']['rows_shown'])->toBe(1);
		expect($o['none']['rows_shown'])->toBe(0);
	});

	it('lists the selected queries in the delete confirmation and then deletes them', function () {
		$o = wmi_e2e_pages()['query_delete'];

		expect($o['confirm_lists'])->toBeTrue();
		expect($o['left'])->toBe(0);
	});

	it('runs a query in the tool and escapes what the host returned', function () {
		$o = wmi_e2e_pages()['tool_run'];

		expect($o['shows_rows'])->toBeTrue();
		expect($o['escapes_markup'])->toBeTrue();
		expect($o['argv'])->toContain('//win-host.invalid');
	});

	it('never stores or echoes a password submitted to the tool', function () {
		$o = wmi_e2e_pages()['tool_run'];

		expect($o['secret_in_reply'])->toBeFalse();
		expect($o['secret_in_page'])->toBeFalse();
		expect($o['secret_in_session'])->toBeFalse();
		expect($o['password_field_empty'])->toBeTrue();
		expect($o['username_in_page'])->toBeTrue();
	});

	it('asks for the missing tool fields', function (string $field) {
		expect(wmi_e2e_pages()['tool_missing_fields'][$field])->toBeTrue();
	})->with(['no_password', 'no_host', 'no_user']);

	it('shows a failed host query as escaped text', function () {
		$o = wmi_e2e_pages()['tool_failure'];

		expect($o['has_error'])->toBeTrue();
		expect($o['escaped'])->toBeTrue();
	});

	it('passes every namespace form from the tool to wmic with single backslashes', function (string $form) {
		expect(wmi_e2e_pages()['tool_namespaces'][$form])->toBe(['--namespace=root\\CIMV2']);
	})->with(['empty', 'single', 'double']);

	it('does not run a tool query without a CSRF token or the WMI realm', function () {
		$o = wmi_e2e_pages();

		expect($o['tool_no_csrf']['ran'])->toBeFalse();
		expect($o['tool_no_realm']['ran'])->toBeFalse();
	});

	it('does not run a tool query sent by GET and echoes no credentials', function () {
		$o = wmi_e2e_pages()['tool_get'];

		expect($o['ran'])->toBeFalse();
		expect($o['wmic_calls'])->toBe(0);
		expect($o['shows_form'])->toBeTrue();
		expect($o['secret_echoed'])->toBeFalse();
	});

	it('builds the device template remove form with an id parameter and links and unlinks queries', function () {
		$o = wmi_e2e_pages();

		expect($o['hook_registered'])->toBeTrue();
		expect($o['hook_confirm']['has_name'])->toBeTrue();
		expect($o['hook_confirm']['action'])->toBe('host_templates.php?action=edit&amp;id=900008');
		expect($o['hook_linked'])->toBe(1);
		expect($o['hook_edit_lists'])->toBeTrue();
		expect($o['hook_unlinked'])->toBe(0);
	});
});
