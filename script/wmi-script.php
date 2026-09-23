<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
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

if (!isset($called_by_script_server)) {
	include_once(__DIR__ . '/../include/cli_check.php');
	array_shift($_SERVER['argv']);

	if (isset($_SERVER['argv'][0]) && $_SERVER['argv'][0] == 'wmi_script') {
		array_shift($_SERVER['argv']);
	}

	print call_user_func_array('wmi_script', $_SERVER['argv']);
}

/**
 * Script-server/CLI entry point that runs a named saved WMI query against
 * a remote host (via the Linux_WMI wrapper around this plugin's bundled
 * wmic binary) and either lists its index keys, prints an indexed
 * key/value pair, or prints a single fetched value, depending on $cmd.
 * Called by Cacti's script server (or directly from the CLI) when a Data
 * Input Method configured to use this script is polled.
 *
 * @param string $hostname The target host's hostname/IP for the WMI
 *                          query.
 * @param int    $host_id  The Cacti host id, used to load any per-host
 *                          WMI credentials.
 * @param string $wmiquery The saved query's name (wmi_wql_queries.name)
 *                          to run.
 * @param string $cmd      The sub-command to perform: 'index' (list index
 *                          keys), 'query' (print an index or a key/value
 *                          pair), or 'get' (print a single value);
 *                          defaults to ''.
 * @param string $arg1     For 'query'/'get', the index value (or
 *                          'index'); defaults to ''.
 * @param string $arg2     For 'query'/'get', the field name to fetch;
 *                          defaults to ''.
 *
 * @return string|void Returns '' when the named query does not exist;
 *                      otherwise prints output directly and returns no
 *                      explicit value.
 */
function wmi_script($hostname, $host_id, $wmiquery, $cmd = '', $arg1 = '', $arg2 = '') {
	global $config;

	include_once($config['base_path'] . '/plugins/wmi/linux_wmi.php');

	$wmi           = new Linux_WMI($host_id);
	$wmi->hostname = $hostname;
	$wmi->binary   = $config['base_path'] . '/plugins/wmi/wmic';

	// Fetch the info for this WMI query from the database, exit if not found
	$wmiinfo = db_fetch_row_prepared('SELECT * FROM wmi_wql_queries WHERE name = ?', [$wmiquery], false);

	if (!isset($wmiinfo['query'])) {
		return '';
	}
	$wmi->indexkey    = $wmiinfo['primary_key'];
	$wmi->command     = $wmiinfo['query'];
	$wmi->querynspace = $wmiinfo['namespace'];

	if ($cmd == 'index') {
		$results = $wmi->fetch();
		$k       = $wmi->fetch_key_index('Name');

		if (isset($results[2])) {
			array_shift($results);
			array_shift($results);

			foreach ($results as $r) {
				print str_replace([' ', '(', ')'], '', $r[$k]) . "\n";
			}
		}
	} elseif ($cmd == 'query') {
		if ($arg1 == 'index') {
			$results = $wmi->fetch();
			$wmi->print_indexes();
		} else {
			$results = $wmi->fetch();
			$wmi->print_fetch_key_value_pair($arg1, $arg2);
		}
	} elseif ($cmd == 'get') {
		$results = $wmi->fetch();
		print $wmi->fetch_value($arg1, $arg2);
	}
}
