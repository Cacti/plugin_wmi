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

/**
 * Renders this plugin's tabbed navigation (Queries, Authentication) atop
 * wmi_queries.php/wmi_accounts.php, highlighting the currently active tab
 * based on the request's 'tab' value. Called from the top of both of
 * those files' page-rendering functions.
 *
 * @return void Outputs HTML directly.
 *
 * @global array $config Cacti global configuration array; used to build
 *                        each tab's link URL.
 */
function display_tabs() {
	global $config;

	// present a tabbed interface
	$tabs['queries']  = ['url' => 'wmi_queries.php',  'name' => __('Queries', 'wmi')];
	$tabs['accounts'] = ['url' => 'wmi_accounts.php', 'name' => __('Authentication', 'wmi')];

	// if they were redirected to the page, let's set that up
	if (!isset_request_var('tab')) {
		$current_tab = 'queries';
	} else {
		$current_tab = get_request_var('tab');
	}

	// draw the tabs
	print "<div class='tabs'><nav><ul>";

	if (cacti_sizeof($tabs)) {
		foreach ($tabs as $shortname => $tab) {
			print '<li><a class="tab ' . (($shortname == $current_tab) ? 'selected"' : '"') . " href='" . htmlspecialchars($config['url_path'] .
				'plugins/wmi/' . $tab['url'] . '?' .
				'tab=' . $shortname) .
				"'>" . $tab['name'] . '</a></li>';
		}
	}

	print '</ul></nav></div>';
}

/**
 * Determines whether any saved WQL query already selects from the same
 * WMI class (table) referenced in the given query's 'FROM' clause,
 * used to warn against creating duplicate/conflicting queries.
 * Currently has no call site in this plugin (wmi_queries.php has no
 * form_save() path that invokes it).
 *
 * @param string $query The WQL query text to check (e.g.
 *                      'SELECT * FROM Win32_Something').
 *
 * @return bool True when an existing saved query already targets the
 *              same WMI class.
 */
function plugin_wmi_query_exists($query) {
	$tokens  = preg_split('/\s+/', $query);
	$tokens  = is_array($tokens) ? $tokens : [];
	$next_ic = false;
	$exists  = false;

	foreach ($tokens as $token) {
		if ($next_ic) {
			$pattern = 'FROM[[:space:]]+' . preg_quote($token, '/') . '\\b';
			$exists  = (bool) db_fetch_cell_prepared('SELECT COUNT(*) FROM wmi_wql_queries WHERE query RLIKE ?', [$pattern]);
		}

		if (strtolower($token) == 'from') {
			$next_ic = true;
		}
	}

	return $exists;
}

/**
 * Generates the Cacti Data Query/Data Template export XML for a saved WMI
 * query, defining a Script Server (Indexed) data query with one data
 * source per primary-key field, suitable for packaging/importing as a
 * reusable Cacti template. Currently has no call site in this plugin.
 *
 * @param int $id The wmi_wql_queries.id to generate export XML for.
 *
 * @return string The generated Cacti export XML, or '' when the query
 *                id does not exist.
 *
 * @global array $config Cacti global configuration array; used to load
 *                        lib/export.php.
 */
function plugin_wmi_create_dataquery_xml($id) {
	global $config;

	include_once($config['base_path'] . '/lib/export.php');
	$wmic = db_fetch_row_prepared('SELECT * FROM wmi_wql_queries WHERE id = ?', [$id]);
	$data = '';

	if (isset($wmic['id'])) {
		$data = "<cacti>\n";

		// Data Query
		$hashes['data_query']    = get_hash_version('data_query') . generate_hash();
		$hashes['data_template'] = get_hash_version('data_template') . generate_hash();

		$data .= "\t<hash_" . $hashes['data_query'] . ">\n";
		$data .= "\t\t<name>" . $wmic['name'] . "</name>\n";
		$data .= "\t\t<description>WMI Query for " . $wmic['name'] . "</description>\n";
		$data .= "\t\t<xml_path>&amp;lt;path_cacti&amp;gt;/resource/script_server/" . $wmic['name'] . ".xml</xml_path>\n";

		$input = db_fetch_cell("SELECT id FROM data_input WHERE name = 'Get Script Server Data (Indexed)'");
		$data .= "\t\t<data_input_id>hash_" . get_hash_version('data_input_method') . get_hash_data_input($input) . "</data_input_id>\n";

		$data .= "\t\t<graphs>\n";
		$hashes['graphs'] = get_hash_version('data_query_graph') . generate_hash();
		$data .= "\t\t\t<hash_" . $hashes['graphs'] . ">\n";

		$data .= "\t\t\t\t<name>" . $wmic['name'] . "</name>\n";
		$data .= "\t\t\t\t<rrd>\n";
		$i    = 0;
		$keys = explode(',', $wmic['primary_key']);

		if (cacti_sizeof($keys) > 0) {
			foreach ($keys as $item2) {
				$data .= "\t\t\t\t\t<item_" . str_pad(strval($i), 3, '0', STR_PAD_LEFT) . ">\n";
				$data .= "\t\t\t\t\t\t<snmp_field_name>" . $item2 . "</snmp_field_name>\n";
				$data .= "\t\t\t\t\t\t<data_template_id>hash_" . $hashes['data_template'] . "</data_template_id>\n";
				$hashes['data_template_item'][$item2] = get_hash_version('data_template_item') . generate_hash();
				$data .= "\t\t\t\t\t\t<data_template_rrd_id>hash_" . $hashes['data_template_item'][$item2] . "</data_template_rrd_id>\n";
				$data .= "\t\t\t\t\t</item_" . str_pad(strval($i), 3, '0', STR_PAD_LEFT) . ">\n";
				$i++;
			}
		}
		$data .= "\t\t\t\t</rrd>\n";

		$data .= "\t\t\t\t<sv_graph>\n";
		$hashes['graph_sv'] = get_hash_version('data_query_sv_graph') . generate_hash();
		$data .= "\t\t\t\t\t<hash_" . $hashes['graph_sv'] . ">\n";
		$data .= "\t\t\t\t\t\t<field_name>title</field_name>\n";
		$data .= "\t\t\t\t\t\t<sequence>2</sequence>\n";
		$data .= "\t\t\t\t\t\t<text>|host_description| - |query_" . $wmic['primary_key'] . "|</text>\n";
		$data .= "\t\t\t\t\t</hash_" . $hashes['graph_sv'] . ">\n";
		$data .= "\t\t\t\t</sv_graph>\n";

		$data .= "\t\t\t\t<sv_data_source>\n";
		$hashes['query_sv'] = get_hash_version('data_query_sv_data_source') . generate_hash();
		$data .= "\t\t\t\t\t<hash_" . $hashes['query_sv'] . ">\n";
		$data .= "\t\t\t\t\t\t<field_name>name</field_name>\n";
		$data .= "\t\t\t\t\t\t<data_template_id>hash_" . $hashes['data_template'] . "</data_template_id>\n";
		$data .= "\t\t\t\t\t\t<sequence>2</sequence>\n";
		$data .= "\t\t\t\t\t\t<text>|host_description| - |query_" . $wmic['primary_key'] . "|</text>\n";
		$data .= "\t\t\t\t\t</hash_" . $hashes['query_sv'] . ">\n";
		$data .= "\t\t\t\t</sv_data_source>\n";

		$data .= "\t\t\t</hash_" . $hashes['graphs'] . ">\n";
		$data .= "\t\t</graphs>\n";
		$data .= "\t</hash_" . $hashes['data_query'] . ">\n";
		// Data Template

		$data .= "\t<hash_" . $hashes['data_template'] . ">\n";
		$data .= "\t\t<name>" . $wmic['name'] . "</name>\n";
		$data .= "\t\t<ds>\n";
		$data .= "\t\t\t<t_name></t_name>\n";
		$data .= "\t\t\t<name>|host_description| - |query_" . $wmic['primary_key'] . "|</name>\n";
		$data .= "\t\t\t<data_input_id>hash_" . get_hash_version('data_input_method') . get_hash_data_input($input) . "</data_input_id>\n";
		$data .= "\t\t\t<t_rra_id></t_rra_id>\n";
		$data .= "\t\t\t<t_rrd_step></t_rrd_step>\n";
		$data .= "\t\t\t<rrd_step></rrd_step>\n";
		$data .= "\t\t\t<t_active>60</t_active>\n";
		$data .= "\t\t\t<active></active>\n";
		$data .= "\t\t\t<rra_items>";
		// Add RRA Items (hashes);
		$data .= "</rra_items>\n";
		$data .= "\t\t</ds>\n";
		$data .= "\t\t<items>\n";

		if (cacti_sizeof($keys) > 0) {
			foreach ($keys as $item2) {
				$data .= "\t\t\t<hash_" . $hashes['data_template_item'][$item2] . ">\n";
				$data .= "\t\t\t\t<t_data_source_name></t_data_source_name>\n";
				$data .= "\t\t\t\t<data_source_name>$item2</data_source_name>\n";
				$data .= "\t\t\t\t<t_rrd_minimum></t_rrd_minimum>\n";
				$data .= "\t\t\t\t<rrd_minimum>0</rrd_minimum>\n";
				$data .= "\t\t\t\t<t_rrd_maximum></t_rrd_maximum>\n";
				$data .= "\t\t\t\t<rrd_maximum>0</rrd_maximum>\n";
				$data .= "\t\t\t\t<t_data_source_type_id></t_data_source_type_id>\n";
				$data .= "\t\t\t\t<data_source_type_id></data_source_type_id>\n";
				// Add correct heartbeat
				$data .= "\t\t\t\t<t_heartbeat>120</t_heartbeat>\n";
				$data .= "\t\t\t\t<heartbeat></heartbeat>\n";
				$data .= "\t\t\t\t<t_data_input_field_id></t_data_input_field_id>\n";
				$data .= "\t\t\t\t<data_input_field_id>0</data_input_field_id>\n";

				$data .= "\t\t\t</hash_" . $hashes['data_template_item'][$item2] . ">\n";
			}
		}
		$data .= "\t\t</items>\n";

		$data_input_data = db_fetch_assoc_prepared("SELECT * FROM data_input_fields WHERE data_input_fields.data_input_id = ? AND input_output = 'in' ORDER BY id DESC", [$input]);
		$data .= "\t\t<data>\n";
		$i = 0;

		if (cacti_sizeof($data_input_data) > 0) {
			foreach ($data_input_data as $item) {
				$data .= "\t\t\t<item_" . str_pad(strval($i), 3, '0', STR_PAD_LEFT) . ">\n";
				$data .= "\t\t\t\t<data_input_field_id>hash_" . get_hash_version('data_input_field') . $item['hash'] . "</data_input_field_id>\n";
				$data .= "\t\t\t\t<t_value>on</t_value>\n";
				$data .= "\t\t\t\t<value></value>\n";
				$data .= "\t\t\t</item_" . str_pad(strval($i), 3, '0', STR_PAD_LEFT) . ">\n";

				$i++;
			}
		}
		$data .= "\t\t</data>\n";

		$data .= "\t</hash_" . $hashes['data_template'] . ">\n";

		/*
			<hash_0100169770d6cedbc65fb620d043cb01c0df94>
				<name>Exchange Messages</name>
				<ds>
					<t_name></t_name>
					<name>|host_description| - |query_StoreName|</name>
					<data_input_id>hash_030016332111d8b54ac8ce939af87a7eac0c06</data_input_id>
					<t_rra_id></t_rra_id>
					<t_rrd_step></t_rrd_step>
					<rrd_step>60</rrd_step>
					<t_active></t_active>
					<active>on</active>
					<rra_items>hash_150016c21df5178e5c955013591239eb0afd46|hash_1500160d9c0af8b8acdc7807943937b3208e29|hash_1500166fc2d038fb42950138b0ce3e9874cc60|hash_150016e36f3adb9f152adfa5dc50fd2b23337e|hash_150016283ea2bf1634d92ce081ec82a634f513</rra_items>
				</ds>
				<items>
					<hash_080016c3e746f787e3c136b94972a63cbcdea7>
						<t_data_source_name></t_data_source_name>
						<data_source_name>MessagesSent</data_source_name>
						<t_rrd_minimum></t_rrd_minimum>
						<rrd_minimum>0</rrd_minimum>
						<t_rrd_maximum></t_rrd_maximum>
						<rrd_maximum>0</rrd_maximum>
						<t_data_source_type_id></t_data_source_type_id>
						<data_source_type_id>2</data_source_type_id>
						<t_rrd_heartbeat></t_rrd_heartbeat>
						<rrd_heartbeat>120</rrd_heartbeat>
						<t_data_input_field_id></t_data_input_field_id>
						<data_input_field_id>0</data_input_field_id>
					</hash_080016c3e746f787e3c136b94972a63cbcdea7>
					<hash_080016b8fccf96a6ae039944956c76e9acde21>
						<t_data_source_name></t_data_source_name>
						<data_source_name>MessagesDelivered</data_source_name>
						<t_rrd_minimum></t_rrd_minimum>
						<rrd_minimum>0</rrd_minimum>
						<t_rrd_maximum></t_rrd_maximum>
						<rrd_maximum>0</rrd_maximum>
						<t_data_source_type_id></t_data_source_type_id>
						<data_source_type_id>2</data_source_type_id>
						<t_rrd_heartbeat></t_rrd_heartbeat>
						<rrd_heartbeat>120</rrd_heartbeat>
						<t_data_input_field_id></t_data_input_field_id>
						<data_input_field_id>0</data_input_field_id>
					</hash_080016b8fccf96a6ae039944956c76e9acde21>
				</items>
				<data>
					<item_000>
						<data_input_field_id>hash_07001631112c85ae4ff821d3b288336288818c</data_input_field_id>
						<t_value>on</t_value>
						<value></value>
					</item_000>
					<item_001>
						<data_input_field_id>hash_07001630fb5d5bcf3d66bb5abe88596f357c26</data_input_field_id>
						<t_value>on</t_value>
						<value></value>
					</item_001>
					<item_002>
						<data_input_field_id>hash_070016172b4b0eacee4948c6479f587b62e512</data_input_field_id>
						<t_value>on</t_value>
						<value></value>
					</item_002>
				</data>
			</hash_0100169770d6cedbc65fb620d043cb01c0df94>
		*/

		// Data Input Method
		$data .= "\t" . str_replace("\t", "\t\t", data_input_method_to_xml($input)) . "\n";

		$data .= "</cacti>\n";
	}

	return $data;
}

/**
 * Generates the Script Server resource XML descriptor for a saved WMI
 * query (wiring up its index/query/get script arguments and declaring
 * its primary-key and output fields), used alongside
 * plugin_wmi_create_dataquery_xml() when packaging a query as a reusable
 * Cacti Data Query resource file. Called from wmi_queries.php's export/
 * download action for a given saved query.
 *
 * @param int $id The wmi_wql_queries.id to generate the resource XML
 *                for.
 *
 * @return string The generated Script Server resource XML, or '' when
 *                the query id does not exist.
 */
function plugin_wmi_create_resource_xml($id) {
	$wmic = db_fetch_row_prepared('SELECT * FROM wmi_wql_queries WHERE id = ?', [$id]);
	$data = '';

	if (isset($wmic['id'])) {
		$data = "<WMIQuery>\n";
		$data .= '	<name>' . $wmic['name'] . "</name>\n";
		$data .= "	<script_path>|path_cacti|/scripts/wmi-script.php</script_path>\n";
		$data .= "	<script_function>wmi_script</script_function>\n";
		$data .= '	<description>WMI Query for ' . $wmic['name'] . "</description>\n";
		$data .= "	<script_server>php</script_server>\n";
		$data .= '	<arg_prepend>|host_hostname| |host_id| ' . $wmic['name'] . "</arg_prepend>\n";
		$data .= "	<arg_index>index</arg_index>\n";
		$data .= "	<arg_query>query</arg_query>\n";
		$data .= "	<arg_get>get</arg_get>\n";
		$data .= "	<output_delimeter>!</output_delimeter>\n";

		$data .= '	<index_order>' . $wmic['primary_key'] . "</index_order>\n";
		$data .= "	<index_order_type>alphabetic</index_order_type>\n";
		$data .= "	<index_title_format>|chosen_order_field|</index_title_format>\n";

		$data .= "	<fields>\n";
		$data .= '		<' . $wmic['primary_key'] . ">\n";
		$data .= '			<name>' . $wmic['primary_key'] . "</name>\n";
		$data .= "			<direction>input</direction>\n";
		$data .= "			<query_name>index</query_name>\n";
		$data .= '		</' . $wmic['primary_key'] . ">\n";

		$fields = explode(',', $wmic['primary_key']);

		if (count($fields)) {
			foreach ($fields as $f) {
				$data .= '		<' . $f . ">\n";
				$data .= '			<name>' . $f . "</name>\n";
				$data .= "			<direction>output</direction>\n";
				$data .= '			<query_name>' . $f . "</query_name>\n";
				$data .= '		</' . $f . ">\n";
			}
		}
		$data .= "	</fields>\n";
		$data .= "</WMIQuery>\n";
	}

	return $data;
}

/**
 * Runs a saved WQL query against a host's configured WMI account (via the
 * Linux_WMI client), storing the results in host_wmi_cache and marking
 * any previously cached rows no longer returned as stale/removed.
 * Called from poller_wmi.php's polling loop for each host/query pairing
 * configured to use WMI-based data collection.
 *
 * @param int $host_id      The Cacti host id to query.
 * @param int $wmi_query_id The wmi_wql_queries.id to run against the
 *                          host.
 *
 * @return bool|void True when the query succeeded and results were
 *                   cached; false when the host's WMI account/query
 *                   could not be found or the query failed; no explicit
 *                   return value when the host itself could not be
 *                   found (stale cache rows are still cleaned up in that
 *                   case).
 *
 * @global array $config Cacti global configuration array; used to load
 *                        this plugin's linux_wmi.php client.
 */
function run_store_wmi_query($host_id, $wmi_query_id) {
	global $config;

	$host_info = db_fetch_row_prepared('SELECT *
		FROM host
		WHERE id = ?',
		[$host_id]);

	$host_info = is_array($host_info) ? $host_info : [];

	// Prepared old entries for removal
	db_execute_prepared('UPDATE host_wmi_cache
		SET present = 0
		WHERE host_id = ?
		AND wmi_query_id = ?',
		[$host_id, $wmi_query_id]);

	if (cacti_sizeof($host_info)) {
		$auth_info = db_fetch_row_prepared('SELECT *
			FROM wmi_user_accounts
			WHERE id = ?',
			[$host_info['wmi_account']]);

		$auth_info = is_array($auth_info) ? $auth_info : [];

		$wmi_query = db_fetch_row_prepared('SELECT *
			FROM wmi_wql_queries
			WHERE id = ?',
			[$wmi_query_id]);

		$wmi_query = is_array($wmi_query) ? $wmi_query : [];

		if (!cacti_sizeof($auth_info)) {
			return false;
		}

		if (!cacti_sizeof($wmi_query)) {
			return false;
		}

		// Set key variables
		$host      = $host_info['hostname'];
		$username  = $auth_info['username'];
		$password  = $auth_info['password'];
		$namespace = $wmi_query['namespace'];
		$command   = $wmi_query['query'];

		// Initialize variables
		$cur_time  = date('Y-m-d H:i:s');
		$data      = [];
		$indexes   = [];

		// Linux_WMI self-selects the PowerShell/CIM transport on a Windows
		// Cacti server and the wmic shell transport otherwise (see
		// Linux_WMI::default_transport()), so the same client is used here
		// regardless of $config['cacti_server_os'].
		include_once($config['base_path'] . '/plugins/wmi/linux_wmi.php');

		$wmi              = new Linux_WMI();
		$wmi->hostname    = $host;
		$wmi->username    = $username;
		$wmi->password    = $wmi->decode($password);
		$wmi->querynspace = $namespace;
		$wmi->command     = $command;
		$wmi->binary      = read_config_option('path_wmi');

		if ($wmi->binary == '') {
			$wmi->binary = '/usr/bin/wmic';
		}

		if ($wmi->querynspace == '') {
			$wmi->querynspace = 'root\\\\CIMV2';
		}

		if ($wmi->fetch() !== false) {
			$indexes = $wmi->fetch_indexes();
			$data    = $wmi->fetch_data();
		} else {
			$indexes = [];
			$data    = [];
		}

		$indexes = is_array($indexes) ? $indexes : [];

		if (cacti_sizeof($data)) {
			$sql = [];

			$pk_index = -1;

			if (cacti_sizeof($indexes)) {
				foreach ($indexes as $index => $value) {
					if ($value == $wmi_query['primary_key']) {
						$pk_index = $index;

						break;
					}
				}
			}

			if (cacti_sizeof($data)) {
				foreach ($data as $row) {
					$pk = isset($row[$pk_index]) ? $row[$pk_index] : 'N/A';

					foreach ($row as $index => $value) {
						if (!isset($indexes[$index])) {
							continue;
						}

						if ($indexes[$index] != 'OEMLogoBitmap') {
							$sql[] = '(' .
								$host_id . ',' .
								$wmi_query_id . ',' .
								db_qstr($indexes[$index]) . ',' .
								db_qstr($value) . ',' .
								db_qstr($pk) . ',' .
								'1' . ',' .
								db_qstr($cur_time) . ')';
						} else {
							$sql[] = '(' .
								$host_id . ',' .
								$wmi_query_id . ',' .
								db_qstr($indexes[$index]) . ',' .
								db_qstr('Not Stored') . ',' .
								db_qstr($pk) . ',' .
								'1' . ',' .
								db_qstr($cur_time) . ')';
						}
					}
				}
			}

			$parts = array_chunk($sql, 200);

			foreach ($parts as $part) {
				db_execute('INSERT INTO host_wmi_cache
					(host_id, wmi_query_id, field_name, field_value, wmi_index, present, last_updated)
					VALUES ' . implode(', ', $part) . '
					ON DUPLICATE KEY UPDATE
						field_value=VALUES(field_value),
						last_updated=VALUES(last_updated),
						present=1');
			}

			return true;
		} else {
			print $wmi->error;

			return false;
		}
	}

	// Remove old entries
	db_execute_prepared('DELETE FROM host_wmi_cache
		WHERE present = 0
		AND host_id = ?
		AND wmi_query_id = ?',
		[$host_id, $wmi_query_id]);
}

/**
 * Returns the current unique hash for a WMI query, or generates a new
 * one if the stored value doesn't already look like a valid 128-bit hex
 * hash; does not itself persist the returned value. Called from
 * wmi_queries.php's save_queries(), which persists the returned hash
 * onto the saved query row.
 *
 * @param int $wmi_query_id The ID of the WMI query to return a hash for.
 *
 * @return string A 128-bit, hexadecimal hash.
 */
function get_hash_wmi_query($wmi_query_id) {
	$hash = db_fetch_cell_prepared('SELECT hash FROM wmi_wql_queries WHERE id = ?', [$wmi_query_id]);

	if (preg_match('/[a-fA-F0-9]{32}/', $hash)) {
		return $hash;
	} else {
		return generate_hash();
	}
}
