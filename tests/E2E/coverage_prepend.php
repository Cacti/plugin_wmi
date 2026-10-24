<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Optional auto_prepend_file for the end-to-end run. It records which lines
 * of this plugin execute and writes them as JSON into the directory named by
 * WMI_COV_DIR when the process ends. It does nothing unless that variable
 * names an existing directory and PHP runs under the CLI or the built-in
 * test server, and it takes nothing from a request. tests/ can end up in a
 * web root, so it never prints, exits or includes a file.
 */

(function (): void {
	$dir = getenv('WMI_COV_DIR');

	if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true) || $dir === false || $dir === '' || !is_dir($dir) || !function_exists('xdebug_start_code_coverage')) {
		return;
	}

	$plugin = dirname(__DIR__, 2);

	// Track only this plugin; Cacti core and its vendor tree would exhaust memory.
	if (function_exists('xdebug_set_filter')) {
		xdebug_set_filter(XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, [$plugin . '/']);
	}

	xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE | XDEBUG_CC_BRANCH_CHECK);

	register_shutdown_function(function () use ($dir, $plugin): void {
		$lines = [];

		foreach (xdebug_get_code_coverage() as $file => $hits) {
			if (strpos($file, $plugin . '/') !== 0 || strpos($file, $plugin . '/tests/') === 0) {
				continue;
			}

			$lines[substr($file, strlen($plugin) + 1)] = $hits;
		}

		xdebug_stop_code_coverage(false);

		if ($lines !== []) {
			file_put_contents($dir . '/' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json', json_encode($lines));
		}
	});
})();
