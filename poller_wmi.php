#!/usr/bin/php -q
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2023 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

chdir(dirname(__FILE__));
chdir('../..');

include('./include/cli_check.php');
include_once('./lib/poller.php');
include_once('./lib/ping.php');
include_once('./plugins/wmi/functions.php');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

global $debug, $start, $seed, $forcerun;

$debug          = FALSE;
$forcerun       = FALSE;
$mainrun        = FALSE;
$host_id        = '';
$start          = '';
$seed           = '';
$key            = '';

if (sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '-d':
			case '--debug':
				$debug = TRUE;
				break;
			case '--host-id':
				$host_id = $value;
				break;
			case '--seed':
				$seed = $value;
				break;
			case '--key':
				$key = $value;
				break;
			case '-f':
			case '--force':
				$forcerun = TRUE;
				break;
			case '-M':
				$mainrun = TRUE;
				break;
			case '-s':
			case '--start':
				$start = $value;
				break;
			case '--version':
			case '-V':
			case '-v':
				display_version();
				exit;
			case '--help':
			case '-H':
			case '-h':
				display_help();
				exit;
			default:
				print 'ERROR: Invalid Parameter ' . $parameter . PHP_EOL . PHP_EOL;
				display_help();
				exit;
		}
	}
}

/* Check for mandatory parameters */
if (!$mainrun && $host_id == '') {
	print "FATAL: You must specify a Cacti host-id run" . PHP_EOL;
	exit;
}

/* Do not process if not enabled */
if (!api_plugin_is_enabled('wmi')) {
	print 'WARNING: The Host WMI Collection is Down!  Exiting' . PHP_EOL;
	exit(0);
}

if ($seed == '') {
	$seed = rand();
}

if ($start == '') {
	$start = microtime(true);
}

if ($mainrun) {
	process_all_devices();
} else {
	process_device($host_id);
}

exit(0);

function debug($message) {
	global $debug;

	if ($debug) {
		print 'DEBUG: ' . trim($message) . PHP_EOL;
	}
}

function process_all_devices() {
	global $start, $seed;

	print "NOTE: Processing Hosts Begins" . PHP_EOL;

	/* Do not process collectors are still running */
	if (db_fetch_cell('SELECT COUNT(*) FROM wmi_processes') > 0) {
		print "WARNING: Another WMI Collector is still running!  Exiting" . PHP_EOL;
		exit(0);
	}

	/* The devices to scan will
	 *  1) Not be disabled,
	 *  2) Be linked to the host table
	 *  3) Be up and operational
	 */
	$devices = db_fetch_assoc("SELECT DISTINCT h.id AS host_id, h.description, h.hostname
        FROM host_template_wmi_query AS htwq
        LEFT JOIN host AS h
        ON htwq.host_template_id = h.host_template_id
        LEFT JOIN host_wmi_query AS hwq
        ON hwq.host_id = h.id
        AND hwq.wmi_query_id = htwq.wmi_query_id
        INNER JOIN wmi_wql_queries AS wwq
        ON wwq.id = htwq.wmi_query_id
        WHERE (UNIX_TIMESTAMP(NOW()) >= UNIX_TIMESTAMP(last_started)+frequency OR last_started IS NULL)
		AND h.status != 1
		AND h.disabled != 'on'
		AND wwq.enabled = 'on'
        AND wmi_account > 0");

	/* Remove entries from  down and disabled devices */
	db_execute("DELETE FROM host_wmi_cache
		WHERE host_id IN(
			SELECT id
			FROM host
			WHERE disabled='on'
			OR host.status=1
		)");

	$concurrent_processes = read_config_option('wmi_processes');

	if (empty($concurrent_processes)) {
		set_config_option('wmi_processes', '10');
	}

	print "NOTE: Launching Collectors Starting" . PHP_EOL;

	$i = 0;
	if (sizeof($devices)) {
		foreach ($devices as $device) {
			while (true) {
				$processes = db_fetch_cell('SELECT COUNT(*) FROM wmi_processes');

				if ($processes < $concurrent_processes) {
					/* put a placeholder in place to prevent overloads on slow systems */
					$key = rand();

					db_execute("INSERT INTO wmi_processes (pid, taskid, started) VALUES ($key, $seed, NOW())");

					print "NOTE: Launching WMI Collector For: '" . $device['description'] . '[' . $device['hostname'] . "]'" . PHP_EOL;

					process_background_device($device['host_id'], $seed, $key);

					usleep(10000);

					break;
				} else {
					sleep(1);
				}
			}
		}

		print "NOTE: All WMI Devices Launched, proceeding to wait for completion" . PHP_EOL;

		/* wait for all processes to end or max run time */
		while (true) {
			$processes_left = db_fetch_cell("SELECT COUNT(*) FROM wmi_processes WHERE taskid = $seed");
			$pl = db_fetch_cell('SELECT COUNT(*) FROM wmi_processes');

			if ($processes_left == 0) {
				print "NOTE: All Processes Complete, Exiting" . PHP_EOL;
				break;
			} else {
				print "NOTE: Waiting on '$processes_left' Processes" . PHP_EOL;
				sleep(2);
			}
		}
	} else {
		print "NOTE: No Devices found this pass to launch" . PHP_EOL;
	}

	if (read_config_option('wmi_autopurge') == 'on') {
		print "NOTE: Auto Purging Devices" . PHP_EOL;

		$dead_devices = db_fetch_assoc('SELECT host_id
			FROM host_wmi_cache AS hwc
			LEFT JOIN host
			ON host.id = hwc.host_id
			WHERE host.id IS NULL');

		if (sizeof($dead_devices)) {
			foreach($dead_devices as $device) {
				db_execute('DELETE FROM host_wmi_cache WHERE host_id='. $device['host_id']);
				db_execute('DELETE FROM host_wmi_query WHERE host_id='. $device['host_id']);
				print "Purged WMI Device with ID '" . $device['host_id'] . "'" . PHP_EOL;
			}
		}
	}

	/* take time and log performance data */
	$end = microtime(true);

	$cacti_stats = sprintf(
		'Time:%01.2f ' .
		'Processes:%s ' .
		'Devices:%s',
		$end - $start,
		$concurrent_processes,
		cacti_sizeof($devices));

	/* log to the database */
	set_config_option('stats_wmi', $cacti_stats);

	/* log to the logfile */
	cacti_log('WMI STATS: ' . $cacti_stats , TRUE, 'SYSTEM');

	print "NOTE: Device WMI Polling Completed, $cacti_stats" . PHP_EOL;
}

function process_background_device($host_id, $seed, $key) {
	global $config, $debug, $start, $forcerun;

	exec_background(read_config_option('path_php_binary'),' -q ' .
		$config['base_path'] . '/plugins/wmi/poller_wmi.php' .
		' --host-id=' . $host_id .
		' --start=' . $start .
		' --seed=' . $seed .
		' --key=' . $key .
		($forcerun ? ' --force':'') .
		($debug ? ' --debug':''));
}

function process_device($host_id) {
	global $config, $start, $seed, $key, $snmp_errors;

	$wmi_errors = 0;

	$queries_to_run = db_fetch_assoc_prepared('SELECT h.id, h.wmi_account, htwq.wmi_query_id, wwq.*
		FROM host_template_wmi_query AS htwq
		LEFT JOIN host AS h
		ON htwq.host_template_id = h.host_template_id
		LEFT JOIN host_wmi_query AS hwq
		ON hwq.host_id = h.id
		AND hwq.wmi_query_id = htwq.wmi_query_id
		INNER JOIN wmi_wql_queries AS wwq
		ON wwq.id = htwq.wmi_query_id
		WHERE (UNIX_TIMESTAMP(NOW()) >= UNIX_TIMESTAMP(last_started)+frequency OR last_started IS NULL)
		AND h.id = ?
		AND wmi_account > 0',
		array($host_id));

	/* remove the key process and insert the set a process lock */
	db_execute('REPLACE INTO wmi_processes (pid, taskid) VALUES (' . getmypid() . ", $seed)");
	db_execute("DELETE FROM wmi_processes WHERE pid = $key AND taskid = $seed");

	$qstart  = date('Y-m-d H:i:s');

	if (cacti_sizeof($queries_to_run)) {
		foreach($queries_to_run AS $q) {
			$qmstart = microtime(true);

			cacti_log("NOTE: Executing WMI Query[" . $q['wmi_query_id'] . "] for Device [$host_id].", false, 'WMI', POLLER_VERBOSITY_MEDIUM);

			$account = db_fetch_row_prepared('SELECT *
				FROM wmi_user_accounts
				WHERE id = ?',
				array($q['wmi_account']));

			if (!cacti_sizeof($account)) {
				cacti_log("WARNING: WMI Account ID " . $q['wmi_account'] . " not found for WMI Device[$host_id].", false, 'WMI');
				break;
			}

			$run_before = db_fetch_row_prepared('SELECT *
				FROM host_wmi_query
				WHERE host_id = ?
				AND wmi_query_id = ?',
				array($host_id, $q['wmi_query_id']));

			if (!cacti_sizeof($run_before)) {
				$last_failed = '0000-00-00 00:00:00';
			} else {
				$last_failed = $run_before['last_failed'];
			}

			// Run the query and store the data
			run_store_wmi_query($host_id, $q['wmi_query_id']);

			$status = 0;

			$qmend = microtime(true);

			if ($status != 0) {
				$last_failed = date('Y-m-d H:i:s');

				cacti_log("WARNING: Errored WMI Query[" . $q['wmi_query_id'] . "] for Device [$host_id] in " . round($qmend - $qmstart, 2) . " seconds.", false, 'WMI');
			} else {
				cacti_log("NOTE: Finished WMI Query[" . $q['wmi_query_id'] . "] for Device [$host_id] in " . round($qmend -$qmstart, 2) . " seconds.", false, 'WMI', POLLER_VERBOSITY_MEDIUM);
			}

			db_execute_prepared('REPLACE INTO host_wmi_query
				(host_id, wmi_query_id, sort_field, title_format, last_started, last_runtime, last_failed)
				VALUES (?, ?, ?, ?, ?, ?, ?)',
				array($host_id, $q['wmi_query_id'], '', '', $qstart, ($qmend - $qmstart), $last_failed)
			);
		}
	} else {
		cacti_log("NOTE: WMI Device[$host_id] had no WMI Queries to run this cycle.", false, 'WMI', POLLER_VERBOSITY_MEDIUM);
	}

	/* remove the process lock */
	db_execute('DELETE FROM wmi_processes WHERE pid=' . getmypid());

	if ($wmi_errors > 0) {
		cacti_log("WARNING: WMI Device[$host_id] experienced $wmi_errors WMI Errors while performing data collection.  Increase logging to HIGH for this device to see the errors.", false, 'WMI');
	}
}

function display_version() {
	global $config;

	if (!function_exists('plugin_wmi_version')) {
		include_once($config['base_path'] . '/plugins/wmi/setup.php');
	}

	$info = plugin_wmi_version();
	print "Device WMI Poller Process, Version " . $info['version'] . ", " . COPYRIGHT_YEARS . PHP_EOL;
}

function display_help() {
	display_version();

	print PHP_EOL;
	print "The Device WMI poller process script for Cacti." . PHP_EOL . PHP_EOL;
	print "usage:" . PHP_EOL;
	print "master process: poller_wmi.php [-M] [-f] [-d]" . PHP_EOL;
	print "child  process: poller_wmi.php --host-id=N [--seed=N] [-f] [-d]" . PHP_EOL . PHP_EOL;
}

