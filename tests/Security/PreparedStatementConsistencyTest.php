<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Verify migrated files use prepared DB helpers for every variable-bearing
 * query. Catches regressions where raw db_execute/db_fetch_* calls with
 * unreviewed variables creep back in.
 *
 * Raw calls built entirely from literal SQL (no PHP variables) are always
 * safe and are skipped automatically. A handful of pre-existing raw calls
 * build their dynamic portion from already-escaped or non-request data
 * (db_qstr()-escaped filters, internal id lists); those are grandfathered
 * via $allowedRawCalls below, each keyed to a snippet unique to that call so
 * an unrelated edit to the same file doesn't silently widen the allowance.
 */

/**
 * Extract the balanced, quote-aware parenthesised argument list for a call.
 *
 * @param string $contents     Full file contents.
 * @param int    $openParenPos Byte offset of the call's opening '('.
 *
 * @return string
 */
function wmi_test_extract_call_arguments($contents, $openParenPos) {
	$depth    = 0;
	$inSingle = false;
	$inDouble = false;
	$len      = strlen($contents);

	for ($i = $openParenPos; $i < $len; $i++) {
		$ch = $contents[$i];

		if ($inSingle) {
			if ($ch === '\\') {
				$i++;
			} elseif ($ch === "'") {
				$inSingle = false;
			}

			continue;
		}

		if ($inDouble) {
			if ($ch === '\\') {
				$i++;
			} elseif ($ch === '"') {
				$inDouble = false;
			}

			continue;
		}

		if ($ch === "'") {
			$inSingle = true;
		} elseif ($ch === '"') {
			$inDouble = true;
		} elseif ($ch === '(') {
			$depth++;
		} elseif ($ch === ')') {
			$depth--;

			if ($depth === 0) {
				return substr($contents, $openParenPos, $i - $openParenPos + 1);
			}
		}
	}

	return substr($contents, $openParenPos);
}

describe('prepared statement consistency in wmi', function () {
	it('uses prepared DB helpers for every variable-bearing query', function () {
		$targetFiles = array(
			'functions.php',
			'poller_wmi.php',
			'setup.php',
			'wmi_accounts.php',
			'wmi_queries.php',
			'wmi_script.php',
			'wmi_tools.php',
			'script/wmi-script.php',
		);

		$allowedRawCalls = array(
			'functions.php' => array(
				"implode(', ', \$part)", // batch insert; every value already escaped via db_qstr() above
			),
			'setup.php' => array(
				"IN(' . \$id . ')", // uninstall only; $id is a GROUP_CONCAT of this plugin's own data_input ids
				'array_to_sql_or($data_sources', // uninstall only; ids come from the query above, not request input
				'data_input_fields', // install only; $id is sql_save()'s own just-inserted autoincrement id
			),
			'wmi_accounts.php' => array(
				'$sql_where', // built via db_qstr(); rows/page filters are FILTER_VALIDATE_INT
			),
			'wmi_queries.php' => array(
				'$sql_where', // built via db_qstr(); rows/page filters are FILTER_VALIDATE_INT
			),
		);

		$rawPattern = '/\bdb_(?:execute|fetch_row|fetch_assoc|fetch_cell)\s*\(/';

		foreach ($targetFiles as $relativeFile) {
			$path = realpath(__DIR__ . '/../../' . $relativeFile);

			if ($path === false) {
				throw new RuntimeException("Unable to resolve path for {$relativeFile}");
			}

			$contents = file_get_contents($path);

			if ($contents === false) {
				throw new RuntimeException("Unable to read contents of {$relativeFile}");
			}

			$allowed    = isset($allowedRawCalls[$relativeFile]) ? $allowedRawCalls[$relativeFile] : array();
			$violations = array();

			if (preg_match_all($rawPattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
				foreach ($matches[0] as $match) {
					$openParenPos = $match[1] + strlen($match[0]) - 1;
					$call         = wmi_test_extract_call_arguments($contents, $openParenPos);

					// A call with no variables at all is a constant literal and is always safe.
					if (strpos($call, '$') === false) {
						continue;
					}

					$isAllowed = false;

					foreach ($allowed as $snippet) {
						if (strpos($call, $snippet) !== false) {
							$isAllowed = true;

							break;
						}
					}

					if (!$isAllowed) {
						$violations[] = trim(preg_replace('/\s+/', ' ', substr($call, 0, 80)));
					}
				}
			}

			expect($violations)->toBe(array(),
				"File {$relativeFile} contains raw (unprepared) DB calls with unreviewed variables: " . implode(' | ', $violations)
			);
		}
	});
});
