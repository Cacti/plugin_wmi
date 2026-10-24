<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Traceability report for the lines a branch changes.
 *
 * Merges the Clover report of the in-process unit tests with the per-process
 * dumps that tests/E2E/coverage_prepend.php writes while the real entry points
 * (poller master and child, web pages, the collector) run, then lists for
 * every changed executable line which suites executed it. Entry points that
 * cannot be loaded in the unit process (poller_wmi.php, wmi_accounts.php,
 * wmi_queries.php, wmi_tools.php) are measured through the dumps.
 *
 * Usage: php tests/bin/e2e-coverage-report.php <base-ref> [clover.xml] [dump-dir]
 *
 * The dump directory holds one sub-directory per driver (collector_flow,
 * pages_flow), each filled by a run with WMI_E2E_COV set.
 *
 * Exits 1 when a changed executable line ran in no suite, 2 on bad input.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);

	exit;
}

if ($argc < 2) {
	fwrite(STDERR, "usage: e2e-coverage-report.php <base-ref> [clover.xml] [dump-dir]\n");

	exit(2);
}

$base_ref = $argv[1];
$clover   = $argv[2] ?? '';
$dump_dir = $argv[3] ?? '';

/**
 * Changed line numbers per production PHP file.
 *
 * @return array<string, array<int, true>>
 */
function wmi_report_changed_lines(string $base_ref): array {
	$lines  = [];
	$status = 0;

	exec('git diff --no-ext-diff --unified=0 --no-color --diff-filter=AM ' . escapeshellarg($base_ref) . '...HEAD -- "*.php"', $lines, $status);

	if ($status !== 0) {
		fwrite(STDERR, "git diff failed\n");

		exit(2);
	}

	$changed = [];
	$file    = null;

	foreach ($lines as $line) {
		if (strncmp($line, '+++ b/', 6) === 0) {
			$file = substr($line, 6);

			if (strncmp($file, 'tests/', 6) === 0) {
				$file = null;

				continue;
			}

			$changed[$file] = [];
		} elseif ($file !== null && strncmp($line, '@@', 2) === 0 && preg_match('/\+(\d+)(?:,(\d+))?/', $line, $m)) {
			$count = isset($m[2]) ? (int) $m[2] : 1;

			for ($i = 0; $i < $count; $i++) {
				$changed[$file][(int) $m[1] + $i] = true;
			}
		}
	}

	return $changed;
}

/**
 * Statement lines of the unit-test Clover report, keyed by file then line,
 * with the number of times each ran.
 *
 * @return array<string, array<int, int>>
 */
function wmi_report_clover(string $path): array {
	if ($path === '' || !is_readable($path)) {
		return [];
	}

	$xml = simplexml_load_file($path);

	if ($xml === false) {
		fwrite(STDERR, "cannot parse $path\n");

		exit(2);
	}

	$result = [];

	foreach ($xml->xpath('//file') as $file) {
		$name = (string) $file['name'];

		if (($pos = strpos($name, '/plugins/wmi/')) !== false) {
			$name = substr($name, $pos + strlen('/plugins/wmi/'));
		}

		foreach ($file->line as $line) {
			if ((string) $line['type'] === 'stmt') {
				$result[$name][(int) $line['num']] = (int) $line['count'];
			}
		}
	}

	return $result;
}

/**
 * Merge the dumps of every driver. Lines: 1 ran, -1 did not run, -2 dead code.
 *
 * @return array{lines: array<string, array<string, array<int, int>>>, branches: array<string, array<string, array<string, array{0: int, 1: int, 2: int}>>>}
 */
function wmi_report_dumps(string $dir): array {
	$merged = ['lines' => [], 'branches' => []];

	if ($dir === '' || !is_dir($dir)) {
		return $merged;
	}

	foreach (glob(rtrim($dir, '/') . '/*', GLOB_ONLYDIR) ?: [] as $driver_dir) {
		$tag = basename($driver_dir);

		foreach (glob($driver_dir . '/*.json') ?: [] as $dump) {
			$data = json_decode((string) file_get_contents($dump), true);

			if (!is_array($data)) {
				continue;
			}

			foreach ($data as $file => $entry) {
				foreach ($entry['lines'] ?? [] as $line => $state) {
					$current = $merged['lines'][$file][$tag][(int) $line] ?? -1;

					$merged['lines'][$file][$tag][(int) $line] = max($current, (int) $state);
				}

				foreach ($entry['functions'] ?? [] as $function => $info) {
					foreach ($info['branches'] ?? [] as $id => $branch) {
						$key = $function . '#' . $id;
						$old = $merged['branches'][$file][$tag][$key] ?? [(int) $branch['line_start'], (int) $branch['line_end'], 0];

						$old[2] = max($old[2], (int) $branch['hit']);

						$merged['branches'][$file][$tag][$key] = $old;
					}
				}
			}
		}
	}

	return $merged;
}

$changed = wmi_report_changed_lines($base_ref);
$unit    = wmi_report_clover($clover);
$dumps   = wmi_report_dumps($dump_dir);

$failed  = false;
$summary = [];

print "Traceability of changed production lines (base $base_ref)\n\n";

ksort($changed);

foreach ($changed as $file => $lines) {
	$rows       = [];
	$executable = 0;
	$covered    = 0;
	$branches   = [];

	ksort($lines);

	$source = is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];

	foreach (array_keys($lines) as $number) {
		$by    = [];
		$known = false;

		// A closing brace is reported as a statement when every path returns
		// earlier; it is not code a test can reach.
		if (in_array(trim($source[$number - 1] ?? ''), ['}', '};', '{'], true)) {
			continue;
		}

		if (isset($unit[$file][$number])) {
			$known = true;

			if ($unit[$file][$number] > 0) {
				$by[] = 'unit';
			}
		}

		foreach ($dumps['lines'][$file] ?? [] as $tag => $state) {
			if (isset($state[$number]) && $state[$number] !== -2) {
				$known = true;

				if ($state[$number] === 1) {
					$by[] = $tag;
				}
			}
		}

		if (!$known) {
			continue;
		}

		$executable++;

		if ($by === []) {
			$failed = true;
			$rows[] = sprintf("  %5d  NOT EXECUTED", $number);
		} else {
			$covered++;
			$rows[] = sprintf("  %5d  %s", $number, implode(', ', $by));
		}
	}

	foreach ($dumps['branches'][$file] ?? [] as $tag => $list) {
		foreach ($list as $key => [$start, $end, $hit]) {
			foreach (array_keys($lines) as $number) {
				if ($number >= $start && $number <= $end) {
					$branches[$key][$tag] = $hit;
					$branches[$key]['range'] = $start . '-' . $end;
				}
			}
		}
	}

	$missed = [];

	foreach ($branches as $key => $tags) {
		$hit = false;

		foreach ($tags as $tag => $value) {
			if ($tag !== 'range' && $value > 0) {
				$hit = true;
			}
		}

		if (!$hit) {
			$missed[] = $key . ' (lines ' . $tags['range'] . ')';
		}
	}

	$summary[$file] = [$executable, $covered, count($branches), count($missed)];

	print $file . "\n";
	print $rows === [] ? "  no executable changed lines\n" : implode("\n", $rows) . "\n";

	if ($missed !== []) {
		print "  branches no end-to-end run took (the unit suites report lines only): " . implode('; ', $missed) . "\n";
	}

	print "\n";
}

print "Summary (changed executable lines; branches overlapping the changed lines)\n";
printf("  %-28s %9s %8s %9s %8s\n", 'file', 'executable', 'covered', 'e2e br.', 'not hit');

foreach ($summary as $file => [$executable, $covered, $branch_count, $branch_missed]) {
	printf("  %-28s %9d %8d %9d %8d\n", $file, $executable, $covered, $branch_count, $branch_missed);
}

print "\nWhole-file line coverage (executable lines known to the unit report or the dumps)\n";
printf("  %-28s %9s %8s %7s\n", 'file', 'executable', 'covered', 'percent');

foreach (array_keys($changed) as $file) {
	$state = [];

	foreach ($unit[$file] ?? [] as $line => $count) {
		$state[$line] = $count > 0;
	}

	foreach ($dumps['lines'][$file] ?? [] as $lines) {
		foreach ($lines as $line => $value) {
			if ($value !== -2) {
				$state[$line] = ($state[$line] ?? false) || $value === 1;
			}
		}
	}

	$total = count($state);
	$ran   = count(array_filter($state));

	printf("  %-28s %9d %8d %6.1f%%\n", $file, $total, $ran, $total === 0 ? 0 : $ran * 100 / $total);
}

exit($failed ? 1 : 0);
