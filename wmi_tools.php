<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2019 The Cacti Group                                 |
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

chdir('../../');
include('./include/auth.php');
include_once('./lib/snmp.php');
include_once('./lib/utility.php');

set_default_action();

process_request_vars();

switch (get_request_var('action')) {
case 'query':
	walk_host();
	break;
case 'queries':
	common_queries_panel();

	break;
case 'assistance':
	assistance_panel();

	break;
default:
	top_header();
	show_tools();
	bottom_footer();

	break;
}

function process_request_vars() {
	/* ================= input validation and session storage ================= */
	$filters = array(
		'username' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
		),
		'password' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
		),
		'namespace' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
		),
		'keyname' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
		),
		'frequency' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '120'
		),
		'host' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
		),
		'name' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => 'New Query',
			'options' => array('options' => 'sanitize_search_string')
		)
	);

	validate_store_request_vars($filters, 'sess_wmic');
	/* ================= input validation ================= */
}

function common_queries_panel() {
	$common = array(
		array(
			'key' => 'ProcessId',
			'tip' => __('Get System Processes'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_Process'
		),
		array(
			'key' => 'None',
			'tip' => __('Get Computer Information'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_ComputerSystem'
		),
		array(
			'key' => 'None',
			'tip' => __('Get Operating System Information'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_OperatingSystem'
		),
		array(
			'key' => 'None',
			'tip' => __('Get System Enclosure Information'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_SystemEnclosure'
		),
		array(
			'key' => 'InterleavePosition',
			'tip' => __('Get System Physical Memory Information'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_PhysicalMemory'
		),
		array(
			'key' => 'DeviceID',
			'tip' => __('Get Memory Device Details'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_MemoryDevice'
		),
		array(
			'key' => 'None',
			'tip' => __('Get System BIOS Information'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_BIOS'
		),
		array(
			'key' => 'None',
			'tip' => __('Get System Baseboard Information'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_BaseBoard'
		),
		array(
			'key' => 'DeviceID',
			'tip' => __('Get Processor Information'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_Processor'
		),
		array(
			'key' => 'None',
			'tip' => __('Ping a Known Address from Computer'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_PingStatus where Address = "www.google.com"'
		),
		array(
			'key' => 'Name',
			'tip' => __('Get Formatted Phsycal Disk Performance Data'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_PerfFormattedData_PerfDisk_PhysicalDisk'
		),
		array(
			'key' => 'Name',
			'tip' => __('Get Raw Phsycal Disk Performance Data'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_PerfRawData_PerfDisk_PhysicalDisk'
		),
		array(
			'key' => 'DeviceID',
			'tip' => __('Get Logical Disk Information'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_LogicalDisk'
		),
		array(
			'key' => 'Name',
			'tip' => __('Get Formatted Logical Disk Performance Data'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_PerfFormattedData_PerfDisk_LogicalDisk'
		),
		array(
			'key' => 'Name',
			'tip' => __('Get Raw Logical Disk Performance Data'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_PerfRawData_PerfDisk_LogicalDisk'
		),
		array(
			'key' => 'Name',
			'tip' => __('Get Formatted CPU Performance Data'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_PerfFormattedData_PerfOS_Processor'
		),
		array(
			'key' => 'Name',
			'tip' => __('Get Raw CPU Performance Data'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_PerfRawData_PerfOS_Processor'
		),
		array(
			'key' => 'None',
			'tip' => __('Get Raw Memory Performance Data'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_PerfRawData_PerfOS_Memory'
		),
		array(
			'key' => 'Name',
			'tip' => __('Get Formatted Network Performance Data'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_PerfFormattedData_Tcpip_NetworkInterface'
		),
		array(
			'key' => 'Name',
			'tip' => __('Get Raw Network Performance Data'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_PerfRawData_Tcpip_NetworkInterface'
		),
		array(
			'key' => 'DeviceID',
			'tip' => __('Get Network Adapter Information'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_NetworkAdapter'
		),
		array(
			'key' => 'None',
			'tip' => __('Get Computer Asset Information'),
			'namespace' => 'root\\\\CIMV2',
			'query' => 'SELECT * FROM Win32_ComputerSystemProduct'
		),
	);

	// Common Queries Panel
	print "<div id='common_queries' style='display:none;'>\n";

	html_start_box('', '100%', '', '3', 'center', '');

	html_header(array(__('Description'), __('Primary Key'), __('Name Space'), __('Query')));

	$i = 0;
	foreach($common as $query) {
		form_alternate_row('line' . $i, true);

		print "<td style='font-weight:bold;'>" . $query['tip'] .
			"</td><td class='keyname'>" . $query['key'] .
			"</td><td class='namespace'>" . $query['namespace'] .
			"</td><td class='query'>" . $query['query'] . "</td>";

		form_end_row();

		$i++;
	}

	print "<tr><td colspan='4' class='odd'><input type='button' id='close_queries' value='" . __('Close') . "'></td></tr>\n";

	html_end_box(false);

	print "</div>\n";
}

// Assistance Panel
function assistance_panel() {
	print "<div id='assistance' style='display:none;'>\n";

	html_start_box('', '100%', '', '3', 'center', '');

	form_alternate_row();
	print '<td>';

	print '<p>' . __('If you need assistance on error codes, use google, or here use the following Link %s.', '<a target="_new" class="linkEditMain" href="https://msdn.microsoft.com/en-us/library/aa394559(v=vs.85).aspx">' . __('Microsoft Common WBEM Errors') . '</a>') . '</p>';
	print '<p>' . __('For WMI to work the user account you are using must be granted Distributed COM permissions, and the Windows Firewall must be configured to allow Distributed COM communications.  You can find a real good document on this procedure at the following Link %s.', '<a target="_new" class="linkEditMain" href="http://www-01.ibm.com/support/docview.wss?uid=swg21678809">' . __('Distributed COM Setup') . '</a>') . '</p>';

	print '</td>';
	form_end_row();

	print "<tr><td colspan='4' class='odd'><input type='button' id='close_help' value='" . __('Close') . "'></td></tr>\n";

	html_end_box(false);

	print "</div>\n";
}

function show_tools() {
	global $action, $host, $username, $password, $command, $wmi_frequencies;

	html_start_box(__('WMI Query Tool') , '100%', '', '3', 'center', '');

	print "<tr><td>\n";

	form_start('wmi_tools.php?action=query&header=false', 'form_wmi');

	print "<table width='100%'>\n";
	print "<tr>\n";
	print "<td valign='center' width='50'>" . __('Name') . "</td>\n";
	print "<td><input type='text' size='40' id='name' value='" . get_request_var('name') . "'></td>\n";
	print "</tr><tr>\n";
	print "<td valign='center' width='50'>" . __('Frequency') . "</td>\n";
	print "<td><select id='frequency'>\n";
	foreach($wmi_frequencies as $key => $name) {
		print "<option value='$key'" . (get_request_var('frequency') == $key ? ' selected':'') . ">" . $name . "</option>\n";
	}
	print "</select></td>\n";
	print "</tr><tr>\n";
	print "<td valign='center' width='50'>" . __('Host') . "</td>\n";
	print "<td><input type='text' size='40' id='host' value='" . get_request_var('host') . "'></td>\n";
	print "</tr><tr>\n";
	print "<td class='nowrap'>" . __('Username') . "</td>";
	print "<td><input type='text' size='30' id='username' value='" . get_request_var('username') . "'></td>";
	print "</tr><tr>\n";
	print "<td class='nowrap'>" . __('Password') . "</td>\n";
	print "<td><input type='password' size='30' id='password' value='" . get_request_var('password') . "'></td>\n";
	print "</tr><tr>\n";
	print "<td class='nowrap'>" . __('Namespace') . "</td>\n";
	print "<td><input type='text' size='30' id='namespace' value='" . get_request_var('namespace') . "'></td>\n";
	print "</tr><tr>\n";
	print "<td class='nowrap'>" . __('Command') . "</td>\n";
	print "<td><textarea class='textAreaNotes' rows='4' cols='80' id='command' value='" . get_request_var('command') . "'></textarea></td>\n";
	print "</tr><tr>\n";
	print "<td class='nowrap'>" . __('Primary Key') . "</td>\n";
	print "<td><input type='text' size='30' id='keyname' value='" . get_request_var('keyname') . "'></td>\n";
	print "</tr>\n";
	print "<tr><td colspan='2'>\n";
	print "<input type='submit' value='" . __('Run') . "' id='submit' title='" . __('Run the WMI Query against the Device') . "'>\n";
	print "<input type='button' value='" . __('Clear') . "' id='clear' title='" . __('Clear the results panel.') . "'>\n";
	print "<input type='button' value='" . __('Queries') . "' id='queries' title='" . __('Pick from a list of common queries.') . "'>\n";
	print "<input type='button' value='" . __('Help') . "' id='help' title='" . __('Get some help on setting up WMI') . "'>\n";
	print "<input type='button' value='" . __('Add') . "' id='add' title='" . __('Create a new WMI Query from the existing Query.') . "'>\n";
	print "</td></tr>\n";
	print "</table>\n";

	form_end();

	print "</td></tr>\n";

	html_end_box();

	// Query Results Panel
	html_start_box(__('Query Results') , '100%', '', '3', 'center', '');

	form_alternate_row();

	print "<td><div class='odd' style='min-height:200px;' id='results'></div></td>\n";

	form_end_row();

	html_end_box();

	?>
	<script type='text/javascript'>
	$(function() {
		<?php if (get_selected_theme() != 'classic') {?>
		$('#add').button('disable');
		<?php }else{?>
		$('#add').prop('disabled', true);
		<?php }?>

		$('#form_wmi').unbind().submit(function(event) {
			event.preventDefault();
			runQuery();
		});

		$('#queries').click(function() {
			$('#assistance').remove();
			$.get('wmi_tools.php?action=queries', function(data) {
				$('body').append(data);
				$('#common_queries').dialog({
					title: '<?php print __('Common Queries (Click to Select)');?>',
					width: '1024',
				});

				$('tr[id^="line"]').css('cursor', 'pointer').attr('title', 'Click to use this Query').tooltip().click(function() {
					$('#command').val($(this).find('.query').html());
					$('#namespace').val($(this).find('.namespace').html());
					$('#keyname').val($(this).find('.keyname').html());
					$('tr[id^="line"]').not(this).removeClass('selected');
					$(this).addClass('selected');
				});

				<?php if (get_selected_theme() != 'classic') {?>
				$('#close_queries').button().click(function() {
					$('#common_queries').remove();
				});
				<?php }else{?>
				$('#close_queries').click(function() {
					$('#common_queries').remove();
				});
				<?php }?>
			});
		});

		$('#help').click(function() {
			$('#common_queries').remove();
			$.get('wmi_tools.php?action=assistance', function(data) {
				$('body').append(data);
				$('#assistance').dialog({
					title: '<?php print __('WMI Setup Assistance');?>',
					width: '1024',
				});

				<?php if (get_selected_theme() != 'classic') {?>
				$('#close_help').button().click(function() {
					$('#assistance').remove();
				});
				<?php }else{?>
				$('#close_help').click(function() {
					$('#assistance').remove();
				});
				<?php }?>
			});
		});

		$('#wmi_tools1').find('.cactiTableTitle, .cactiTableBottom').css('cursor', 'pointer').click(function() {
			$('#wmi_tools1_child').toggle();
		});

		$('#wmi_tools2').find('.cactiTableTitle, .cactiTableBottom').css('cursor', 'pointer').click(function() {
			$('#wmi_tools2_child').toggle();
		});

		$('#wmi_tools3').find('.cactiTableTitle, .cactiTableBottom').css('cursor', 'pointer').click(function() {
			$('#wmi_tools3_child').toggle();
		});

		$('#clear').click(function() {
			$('#results').empty();
			<?php if (get_selected_theme() != 'classic') {?>
			$('#submit').button('enable');
			<?php }else{?>
			$('#submit').prop('disabled', false);
			<?php }?>
		});

		$('#add').click(function() {
			post = {
				__csrf_magic: csrfMagicToken,
				name: $('#name').val(),
				frequency: $('#frequency').val(),
				namespace: $('#namespace').val(),
				enabled: '',
				query: $('#command').val(),
				primary_key: $('#keyname').val()
			};

			$.post('wmi_queries.php?action=save', post).done(function(data) {
				$('#main').html(data);
				applySkin();
			});
		});
	});

	function runQuery() {
		$.post('wmi_tools.php?action=query&header=false', { host: $('#host').val(), username: $('#username').val(), password: $('#password').val(), namespace: $('#namespace').val(), command: $('#command').val(), __csrf_magic: csrfMagicToken }).done(function(data) {
			$('#results').html(data);
			applySkin();
			<?php if (get_selected_theme() != 'classic') {?>
			$('#submit').button('enable');
			<?php }else{?>
			$('#submit').prop('disabled', false);
			<?php }?>
			if (data.indexOf('ERROR:') == -1) {
				<?php if (get_selected_theme() != 'classic') {?>
				$('#add').button('enable');
				<?php }else{?>
				$('#add').prop('disabled', false);
				<?php }?>
			}
		});
	}

	</script>
	<?php
}

function walk_host() {
	global $config, $host;

	$host      = get_nfilter_request_var('host');
	$username  = get_nfilter_request_var('username');
	$password  = get_nfilter_request_var('password');
	$namespace = get_nfilter_request_var('namespace');

	if (!isset_request_var('command')) {
		$command = 'SELECT * FROM Win32_Process';
	}else{
		$command = get_nfilter_request_var('command');
	}

	$host = strtolower($host);

	if ($username == '' || $password == '' || $host == '') {
		print __('ERROR: You must provide a host, username, password and query');
		exit;
	}

	if ($config['cacti_server_os'] != 'win32') {
		include_once($config['base_path'] . '/plugins/wmi/linux_wmi.php');

		$wmi = new Linux_WMI();
		$wmi->hostname    = $host;
		$wmi->username    = $username;
		$wmi->password    = $password;
		$wmi->querynspace = $namespace;
		$wmi->command     = $command;
		$wmi->binary      = read_config_option('path_wmi');

		if ($wmi->binary == '') {
			$wmi->binary = '/usr/bin/wmic';
		}

		if ($wmi->querynspace == '') {
			$wmi->querynspace = 'root\\\\CIMV2';
		}

		if ($wmi->fetch() !== false) {;
			print "<table style='width:100%'><tr><td class='even'>\n";

			$indexes = $wmi->fetch_indexes();
			$class   = $wmi->fetch_class();
			$data    = $wmi->fetch_data();

			print "<h4>" . __('WMI Query Results for Device: %s, Class: %s, Columns: %s, Rows: %s', $host, $class, sizeof($indexes), sizeof($data)) . "</h4>\n";

			print "<p>" . __('Showing columns and first one or two rows of data.') . "</p>\n";

			print "</table>";
			print "<table style='width:100%'>\n";

			$present = 'columns';

			if ($present == 'columns') {
				if (sizeof($data[0])) {
					foreach($data[0] as $index => $r) {
						form_alternate_row('line' . $index, true);

						print "<td style='font-weight:bold;'>" . $indexes[$index] . "</td><td>" . $r . "</td>\n";

						if (isset($data[1][$index])) {
							print "<td style='font-weight:bold;'>" . $indexes[$index] . "</td><td>" . $data[1][$index] . "</td>\n";
						}

						form_end_row();
					}
				}
			}else{
				foreach($data as $row) {
					$indexes = array_keys($row);
					if (sizeof($indexes)) {
						print "<tr>\n";
						foreach($indexes as $col) {
							print "<th>" . $col . "</th>\n";
						}
						print "</tr>\n";
					}

					print "<tr>\n";
					foreach($row as $data) {
						print "<td>" . $data . "</td>\n";
					}
					print "</tr>\n";
				}
			}

			print "</table>";
		}else{
			print $wmi->error;
		}
	}else{
		// Windows version
		$wmi  = new COM('WbemScripting.SWwebLocator');
		$wmic = $wmi->ConnectServer($host, $namespace, $username, $password);
		$wmic->Security_->ImpersonationLevel = 3;

		$data = $wmic->ExecQuery($command);

		if (sizeof($data)) {
			$odata = (array) $data[0];
			$indexes = array_keys($odata);
			if (isset($data[1])) {
				$odata1 = (array) $data[1];
			}else{
				$odata1 = array();
			}

			print "<table style='width:100%'><tr><td>\n";

			print "<h4>" . __('WMI Query Results for Device: %s, Class: %s, Columns: %s, Rows: %s', $host, $namespace, sizeof($indexes), sizeof($data)) . "</h4>\n";

			print "<p>" . __('Showing columns and first one or two rows of data.') . "</p>\n";

			print "</table>";
			print "<table style='width:100%'>\n";

			if (sizeof($odata)) {
				foreach($odata as $index => $r) {
					form_alternate_row('line' . $index, true);

					print "<td style='font-weight:bold;'>" . $indexes[$index] . "</td><td>" . $r . "</td>\n";

					if (sizeof($odata1)) {
						print "<td style='font-weight:bold;'>" . $indexes[$index] . "</td><td>" . $odata1[$index] . "</td>\n";
					}

					form_end_row();
				}
			}

			print "</table>";
		}
	}
}

function is_valid_host($host) {
	if(preg_match('/^((([0-9]{1,3}\.){3}[0-9]{1,3})|([0-9a-z-.]{0,61})?\.[a-z]{2,4})$/i', $host)) {
		return true;
	}

	return false;
}
