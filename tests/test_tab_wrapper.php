<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * WMI has no shared "render tab page" helper like some other plugins;
 * each console page inlines top_header()/bottom_footer() around its
 * content function. Verify that ordering holds for every tab page so a
 * refactor can't drop the header or footer call.
 */

describe('wmi tab page wrapper ordering', function () {
	$pages = array(
		'wmi_accounts.php' => array('display_tabs', 'show_accounts'),
		'wmi_queries.php'  => array('display_tabs', 'show_queries'),
		'wmi_tools.php'    => array('show_tools'),
	);

	foreach ($pages as $relativeFile => $contentCalls) {
		it("wraps the default action of {$relativeFile} with top_header() and bottom_footer()", function () use ($relativeFile, $contentCalls) {
			$path = realpath(__DIR__ . '/../' . $relativeFile);

			if ($path === false) {
				throw new RuntimeException("Unable to resolve path for {$relativeFile}");
			}

			$source = file_get_contents($path);

			if ($source === false) {
				throw new RuntimeException("Unable to read contents of {$relativeFile}");
			}

			if (!preg_match('/default:(.*?)break;/s', $source, $matches)) {
				throw new RuntimeException("Unable to locate default action in {$relativeFile}");
			}

			$defaultAction = $matches[1];

			$headerPos = strpos($defaultAction, 'top_header()');
			$footerPos = strpos($defaultAction, 'bottom_footer()');

			expect($headerPos)->not->toBeFalse("{$relativeFile} default action should call top_header()");
			expect($footerPos)->not->toBeFalse("{$relativeFile} default action should call bottom_footer()");

			foreach ($contentCalls as $call) {
				$callPos = strpos($defaultAction, $call . '(');

				expect($callPos)->not->toBeFalse("{$relativeFile} default action should call {$call}()");
				expect($callPos)->toBeGreaterThan($headerPos, "{$relativeFile} should call {$call}() after top_header()");
				expect($callPos)->toBeLessThan($footerPos, "{$relativeFile} should call {$call}() before bottom_footer()");
			}
		});
	}
});
