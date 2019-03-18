<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2019 The Cacti Group                                 |
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

function display_tabs() {
	global $config;

	/* present a tabbed interface */
	$tabs['queries']  = array('url' => 'wmi_queries.php',  'name' => __('Queries'));
	$tabs['accounts'] = array('url' => 'wmi_accounts.php', 'name' => __('Authentication'));

	/* if they were redirected to the page, let's set that up */
	if (!isset_request_var('tab')) {
		$current_tab = 'queries';
	}else{
		$current_tab = get_request_var('tab');
	}

	/* draw the tabs */
	print "<div class='tabs'><nav><ul>\n";

	if (sizeof($tabs)) {
		foreach ($tabs as $shortname => $tab) {
			print '<li><a class="tab ' . (($shortname == $current_tab) ? 'selected"':'"') . " href='" . htmlspecialchars($config['url_path'] .
				'plugins/wmi/' . $tab['url'] . '?' .
				'tab=' . $shortname) .
				"'>" . $tab['name'] . "</a></li>\n";
		}
	}

	print "</ul></nav></div>\n";
}

function plugin_wmi_query_exists($query) {
	$tokens  = preg_split('/\s+/', $query);
	$next_ic = false;
	$exists  = false;

	foreach($tokens as $token) {
		if ($next_ic) {
			$exists = db_fetch_cell("SELECT COUNT(*) FROM wmi_wql_queries WHERE query RLIKE '^FROM\s$token$+'");
		}

		if (strtolower($token) == 'from') {
			$next_ic = true;
		}
	}
}

function plugin_wmi_create_dataquery_xml($id) {
	global $config;

	include_once($config['base_path'] . '/lib/export.php');
	$wmic = db_fetch_row("SELECT * FROM wmi_wql_queries WHERE id = $id");
	$data = '';
	if (isset($wmic['id'])) {
		$data = "<cacti>\n";

// Data Query
		$hashes['data_query'] = get_hash_version("data_query") . generate_hash();
		$hashes['data_template'] = get_hash_version("data_template") . generate_hash();

		$data .= "\t<hash_" . $hashes['data_query'] . ">\n";
		$data .= "\t\t<name>" . $wmic['name'] . "</name>\n";
		$data .= "\t\t<description>WMI Query for " . $wmic['name'] . "</description>\n";
		$data .= "\t\t<xml_path>&amp;lt;path_cacti&amp;gt;/resource/script_server/" . $wmic['queryname'] . ".xml</xml_path>\n";

		$input = db_fetch_cell("SELECT id FROM data_input WHERE name = 'Get Script Server Data (Indexed)'");
		$data .= "\t\t<data_input_id>hash_" . get_hash_version("data_input_method") . get_hash_data_input($input) . "</data_input_id>\n";

		$data .= "\t\t<graphs>\n";
		$hashes['graphs'] = get_hash_version("data_query_graph") . generate_hash();
		$data .= "\t\t\t<hash_" . $hashes['graphs'] . ">\n";

		$data .= "\t\t\t\t<name>" . $wmic['name'] . "</name>\n";
		$data .= "\t\t\t\t<rrd>\n";
		$i = 0;
		$keys = explode(',', $wmic['querykeys']);

		if (sizeof($keys) > 0) {
			foreach ($keys as $item2) {
				$data .= "\t\t\t\t\t<item_" . str_pad(strval($i), 3, "0", STR_PAD_LEFT) . ">\n";
				$data .= "\t\t\t\t\t\t<snmp_field_name>" . $item2 . "</snmp_field_name>\n";
				$data .= "\t\t\t\t\t\t<data_template_id>hash_" . $hashes['data_template'] . "</data_template_id>\n";
				$hashes['data_template_item'][$item2] = get_hash_version("data_template_item") . generate_hash();
				$data .= "\t\t\t\t\t\t<data_template_rrd_id>hash_" . $hashes['data_template_item'][$item2] . "</data_template_rrd_id>\n";
				$data .= "\t\t\t\t\t</item_" . str_pad(strval($i), 3, "0", STR_PAD_LEFT) . ">\n";
				$i++;
			}
		}
		$data .= "\t\t\t\t</rrd>\n";

		$data .= "\t\t\t\t<sv_graph>\n";
		$hashes['graph_sv'] = get_hash_version("data_query_sv_graph") . generate_hash();
		$data .= "\t\t\t\t\t<hash_" . $hashes['graph_sv']. ">\n";
		$data .= "\t\t\t\t\t\t<field_name>title</field_name>\n";
		$data .= "\t\t\t\t\t\t<sequence>2</sequence>\n";
		$data .= "\t\t\t\t\t\t<text>|host_description| - |query_" . $wmic['indexkey'] . "|</text>\n";
		$data .= "\t\t\t\t\t</hash_" . $hashes['graph_sv'] . ">\n";
		$data .= "\t\t\t\t</sv_graph>\n";

		$data .= "\t\t\t\t<sv_data_source>\n";
		$hashes['query_sv'] = get_hash_version("data_query_sv_data_source") . generate_hash();
		$data .= "\t\t\t\t\t<hash_" . $hashes['query_sv'] . ">\n";
		$data .= "\t\t\t\t\t\t<field_name>name</field_name>\n";
		$data .= "\t\t\t\t\t\t<data_template_id>hash_" . $hashes['data_template'] . "</data_template_id>\n";
		$data .= "\t\t\t\t\t\t<sequence>2</sequence>\n";
		$data .= "\t\t\t\t\t\t<text>|host_description| - |query_" . $wmic['indexkey'] . "|</text>\n";
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
		$data .= "\t\t\t<name>|host_description| - |query_" . $wmic['indexkey'] . "|</name>\n";
		$data .= "\t\t\t<data_input_id>hash_" . get_hash_version("data_input_method") . get_hash_data_input($input) . "</data_input_id>\n";
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

		if (sizeof($keys) > 0) {
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

		$data_input_data = db_fetch_assoc("SELECT * FROM data_input_fields WHERE data_input_fields.data_input_id=$input AND input_output = 'in' ORDER BY id DESC");
		$data .= "\t\t<data>\n";
		$i = 0;
		if (sizeof($data_input_data) > 0) {
			foreach ($data_input_data as $item) {
				$data .= "\t\t\t<item_" . str_pad(strval($i), 3, "0", STR_PAD_LEFT) . ">\n";
				$data .= "\t\t\t\t<data_input_field_id>hash_" . get_hash_version("data_input_field") . $item['hash'] . "</data_input_field_id>\n";
				$data .= "\t\t\t\t<t_value>on</t_value>\n";
				$data .= "\t\t\t\t<value></value>\n";
				$data .= "\t\t\t</item_" . str_pad(strval($i), 3, "0", STR_PAD_LEFT) . ">\n";

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

function plugin_wmi_create_resource_xml($id) {
	$wmic = db_fetch_row("SELECT * FROM wmi_wql_queries WHERE id = $id");
	$data = '';
	if (isset($wmic['id'])) {
		$data = "<WMIQuery>\n";
		$data .= '	<name>' . $wmic['name'] . "</name>\n";
		$data .= "	<script_path>|path_cacti|/scripts/wmic-script.php</script_path>\n";
		$data .= "	<script_function>wmic_script</script_function>\n";
		$data .= '	<description>WMI Query for ' . $wmic['name'] . "</description>\n";
		$data .= "	<script_server>php</script_server>\n";
		$data .= '	<arg_prepend>|host_hostname| |host_id| ' . $wmic['queryname'] . "</arg_prepend>\n";
		$data .= "	<arg_index>index</arg_index>\n";
		$data .= "	<arg_query>query</arg_query>\n";
		$data .= "	<arg_get>get</arg_get>\n";
		$data .= "	<output_delimeter>!</output_delimeter>\n";

		$data .= '	<index_order>' . $wmic['indexkey'] . "</index_order>\n";
		$data .= "	<index_order_type>alphabetic</index_order_type>\n";
		$data .= "	<index_title_format>|chosen_order_field|</index_title_format>\n";

		$data .= "	<fields>\n";
		$data .= '		<' . $wmic['indexkey'] . ">\n";
		$data .= '			<name>' . $wmic['indexkey'] . "</name>\n";
		$data .= "			<direction>input</direction>\n";
		$data .= "			<query_name>index</query_name>\n";
		$data .= '		</' . $wmic['indexkey'] . ">\n";

		$fields = explode(',', $wmic['querykeys']);
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


