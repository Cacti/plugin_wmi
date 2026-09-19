<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Verify setup.php defines required plugin hooks and info function.
 */

describe('wmi setup.php structure', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../setup.php'));

	it('defines plugin_wmi_install function', function () use ($source) {
		expect($source)->toContain('function plugin_wmi_install');
	});

	it('defines plugin_wmi_version function', function () use ($source) {
		expect($source)->toContain('function plugin_wmi_version');
	});

	it('defines plugin_wmi_uninstall function', function () use ($source) {
		expect($source)->toContain('function plugin_wmi_uninstall');
	});

	it('INFO metadata provides a name', function () {
		$info = parse_ini_file(realpath(__DIR__ . '/../../INFO'), true);

		expect($info['info'])->toHaveKey('name');
		expect($info['info']['name'])->not->toBeEmpty();
	});

	it('INFO metadata provides a version', function () {
		$info = parse_ini_file(realpath(__DIR__ . '/../../INFO'), true);

		expect($info['info'])->toHaveKey('version');
		expect($info['info']['version'])->not->toBeEmpty();
	});
});
