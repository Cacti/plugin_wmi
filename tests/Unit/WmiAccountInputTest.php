<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

require_once __DIR__ . '/../../includes/functions.php';

it('accepts a new account with a confirmed password', function () {
	expect(wmi_account_input_valid('Lab', 'dom\\user', 'secret', 'secret', 0))->toBeTrue();
});

it('rejects a password that does not match its confirmation', function () {
	expect(wmi_account_input_valid('Lab', 'dom\\user', 'secret', 'other', 0))->toBeFalse();
	expect(wmi_account_input_valid('Lab', 'dom\\user', 'secret', 'other', 4))->toBeFalse();
});

it('requires a password for a new account but not when editing one', function () {
	expect(wmi_account_input_valid('Lab', 'dom\\user', '', '', 0))->toBeFalse();
	expect(wmi_account_input_valid('Lab', 'dom\\user', '', '', 4))->toBeTrue();
});

it('requires a name and a username', function () {
	expect(wmi_account_input_valid('', 'dom\\user', 'secret', 'secret', 4))->toBeFalse();
	expect(wmi_account_input_valid('Lab', '', 'secret', 'secret', 4))->toBeFalse();
});

it('accepts an encoded password that fits the column and rejects one that does not', function () {
	$w = new Linux_WMI();

	expect(wmi_account_password_fits($w->encode(str_repeat('a', 64))))->toBeTrue();
	expect(wmi_account_password_fits($w->encode(str_repeat('a', 200))))->toBeFalse();
	expect(wmi_account_password_fits(str_repeat('x', 256)))->toBeTrue();
	expect(wmi_account_password_fits(str_repeat('x', 257)))->toBeFalse();
});

it('validates WinRM port, authentication, and HTTPS requirements per account', function () {
	expect(wmi_account_winrm_input_valid('inherit', '', 'inherit'))->toBeTrue();
	expect(wmi_account_winrm_input_valid('on', '5986', 'Basic'))->toBeTrue();
	expect(wmi_account_winrm_input_valid('off', '', 'Basic'))->toBeFalse();
	expect(wmi_account_winrm_input_valid('on', '0', 'Kerberos'))->toBeFalse();
	expect(wmi_account_winrm_input_valid('on', '65536', 'Kerberos'))->toBeFalse();
	expect(wmi_account_winrm_input_valid('on', 'not-a-port', 'Negotiate'))->toBeFalse();
	expect(wmi_account_winrm_input_valid('invalid', '', 'Negotiate'))->toBeFalse();
	expect(wmi_account_winrm_input_valid('on', '', 'CredSSP'))->toBeFalse();
});
