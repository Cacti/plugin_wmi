<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2020 The Cacti Group                                 |
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
include_once($config['base_path'] . '/plugins/wmi/functions.php');
include_once($config['base_path'] . '/plugins/wmi/linux_wmi.php');

$ds_actions = array(
	1 => __('Delete', 'wmi')
);

if (!isset_request_var('tab')) {
	set_request_var('tab', 'queries');
}

global $wmi_frequencies;


set_default_action();

$ns = array('root\\\\CIMV2', 'root\\\\MSCluster');

$query_edit = array(
	'name' => array(
		'method' => 'textbox',
		'friendly_name' => __('Name', 'wmi'),
		'description' => __('Give this query a meaningful name that will be displayed.', 'wmi'),
		'value' => '|arg1:name|',
		'max_length' => '64',
	),
	'frequency' => array(
		'method' => 'drop_array',
		'friendly_name' => __('Collection Frequency', 'wmi'),
		'description' => __('When this WMI Query is added to a Device, this is the Frequency of Data Collection that will be used.', 'wmi'),
		'value' => '|arg1:frequency|',
		'default' => '300',
		'array' => $wmi_frequencies
	),
	'enabled' => array(
		'method' => 'checkbox',
		'friendly_name' => __('Enabled', 'wmi'),
		'description' => __('Should this Query be enabled on hosts using it', 'wmi'),
		'value' => '|arg1:enabled|',
		'default' => ''
	),
	'namespace' => array(
		'method' => 'textbox',
		'friendly_name' => __('Namespace', 'wmi'),
		'description' => __('The Namespace for this Query.', 'wmi'),
		'value' => '|arg1:namespace|',
		'max_length' => '64',
	),
	'query' => array(
		'method' => 'textarea',
		'friendly_name' => __('Query', 'wmi'),
		'description' => __('The Query to execute for gathering WMI data from the device.', 'wmi'),
		'value' => '|arg1:query|',
		'textarea_rows' => '4',
		'textarea_cols' => '80',
		'max_length' => '1024',
	),
	'primary_key' => array(
		'method' => 'textbox',
		'friendly_name' => __('Primary Key', 'wmi'),
		'description' => __('When a WMI Query returns multiple rows, which Keyname will be the primary key or index?  If the Primary Key includes multiple columns, separate them with a comma.', 'wmi'),
		'value' => '|arg1:primary_key|',
		'max_length' => '128',
	),
	'id' => array(
		'method' => 'hidden_zero',
		'value' => '|arg1:id|'
	)
);

switch (get_request_var('action')) {
	case 'actions':
		actions_queries();
		break;
	case 'save':
		save_queries();
		break;
	case 'edit':
		top_header();
		display_tabs();
		edit_queries();
		bottom_footer();
		break;
	default:
		top_header();
		display_tabs();
		show_queries();
		bottom_footer();
		break;
}

function actions_queries() {
	global $colors, $ds_actions, $config;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_post('drp_action'));
	/* ==================================================== */

	if (isset_request_var('selected_items')) {
        $selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

        if ($selected_items != false) {
			if (get_request_var('drp_action') == '1') {
				for ($i=0; $i<count($selected_items); $i++) {
					/* ================= input validation ================= */
					input_validate_input_number($selected_items[$i]);
					/* ==================================================== */

					db_execute_prepared('DELETE FROM host_wmi_query WHERE wmi_query_id = ?', array($selected_items[$i]));
					db_execute_prepared('DELETE FROM host_wmi_cache WHERE wmi_query_id = ?', array($selected_items[$i]));
					db_execute_prepared('DELETE FROM host_template_wmi_query WHERE wmi_query_id = ?', array($selected_items[$i]));
					db_execute_prepared('DELETE FROM wmi_wql_queries WHERE id = ?', array($selected_items[$i]));
				}
			}
		}

		header('Location: wmi_queries.php?header=false');
		exit;
	}


	/* setup some variables */
	$query_list = '';

	/* loop through each of the queries selected on the previous page and get more info about them */
	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$query_list .= '<li>' . db_fetch_cell_prepared('SELECT name
				FROM wmi_wql_queries
				WHERE id = ?',
				array($matches[1])) . '</li>';

			$query_array[] = $matches[1];
		}
	}

	top_header();

	form_start('wmi_queries.php');

	html_start_box($ds_actions[get_request_var('drp_action')], '60%', '', '3', 'center', '');

	if (get_request_var('drp_action') == '1') { /* Delete */
		print "<tr>
			<td colspan='2' class='textArea'>
				<p>" . __('Click \'Continue\' to Delete the following WMI Queries.', 'wmi') . "</p>
				<div class='itemlist'><ul>$query_list</ul></div>
				</td>
		</tr>";
	}

	if (!isset($query_array)) {
		print "<tr><td class='odd'><span class='textError'>" . __('You must select at least one WMI Query.', 'wmi') . "</span></td></tr>";
		$save_html = "<input type='button' value='" . __('Return', 'wmi') . "' onClick='cactiReturnTo()'>";
	}else{
		$save_html = "<input type='button' value='" . __('Cancel', 'wmi') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __('Continue', 'wmi') . "' title='" . __('Delete WMI Query', 'wmi') . "'>";
	}

	print "<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($query_array) ? serialize($query_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . get_request_var('drp_action') . "'>
			$save_html
		</td>
	</tr>";

	html_end_box();

	form_end();

	bottom_footer();
}

function save_queries() {
	if (isset_request_var('id')) {
		$save['id'] = get_filter_request_var('id');
	} else {
		$save['id'] = '';
	}

	$save['name']        = get_nfilter_request_var('name');
	$save['hash']        = get_hash_wmi_query($save['id']);
	$save['namespace']   = get_nfilter_request_var('namespace');
	$save['frequency']   = get_filter_request_var('frequency');
	$save['enabled']     = isset_request_var('enabled') ? 'on':'';
	$save['query']       = get_nfilter_request_var('query');
	$save['primary_key'] = get_nfilter_request_var('primary_key');

	$id = sql_save($save, 'wmi_wql_queries', 'id');

	if (is_error_message()) {
		header('Location: wmi_queries.php?header=false&action=edit&id=' . (empty($id) ? get_request_var('id') : $id));
		exit;
	}

	header('Location: wmi_queries.php?header=false');
	exit;
}

function edit_queries() {
	global $query_edit;

	$query = array();
	if (isset_request_var('id')) {
		$query = db_fetch_row_prepared('SELECT *
			FROM wmi_wql_queries
			WHERE id= ?',
			array(get_filter_request_var('id')));

		$header_label = __('Query [edit: %s]', $query['name'], 'wmi');
	}else{
		$header_label = __('Query [new]', 'wmi');
	}

	form_start('wmi_queries.php', 'query_edit');

	html_start_box($header_label, '100%', '', '3', 'center', '');

	draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => inject_form_variables($query_edit, $query)
		)
	);

	html_end_box();

	form_save_button('wmi_queries.php');
}

function query_filter() {
	global $item_rows;

	html_start_box( __('WMI Queries', 'wmi'), '100%', '', '3', 'center', 'wmi_queries.php?action=edit');
	?>
	<tr class='even'>
		<td>
			<form id='form_wmi' action='queries.php'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'wmi');?>
					</td>
					<td>
						<input type='text' id='filter' size='25' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Queries', 'wmi');?>
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php print (get_request_var('rows') == '-1' ? ' selected>':'>') . __('Default', 'wmi');?></option>
							<?php
							if (sizeof($item_rows)) {
								foreach ($item_rows as $key => $value) {
									print "<option value='" . $key . "'"; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . $value . "</option>";
								}
							}
							?>
						</select>
					</td>
                    <td>
                        <input type='checkbox' id='has_graphs' <?php print (get_request_var('has_graphs') == 'true' ? 'checked':'');?>>
                    </td>
					<td>
						<label for='has_graphs'><?php print __('Has Graphs', 'wmi');?></label>
					</td>
					<td>
						<input type='button' Value='<?php print __x('filter: use', 'Go');?>' id='refresh'>
					</td>
					<td>
						<input type='button' Value='<?php print __x('filter: reset', 'Clear');?>' id='clear'>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' value='<?php print get_filter_request_var('page');?>'>
			</form>
			<script type='text/javascript'>

			function applyFilter() {
				strURL = 'wmi_queries.php?filter='+$('#filter').val()+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&has_graphs='+$('#has_graphs').is(':checked')+'&header=false';
				loadPageNoHeader(strURL);
			}

			function clearFilter() {
				strURL = 'wmi_queries.php?clear=1&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#refresh').click(function() {
					applyFilter();
				});

				$('#has_graphs').click(function() {
					applyFilter();
				});

				$('#clear').click(function() {
					clearFilter();
				});

				$('#form_wmi').submit(function(event) {
					event.preventDefault();
					applyFilter();
				});
			});

			</script>
		</td>
	</tr>
	<?php

	html_end_box();
}

function show_queries() {
	global $action, $host, $username, $password, $command;
	global $config, $ds_actions;

	/* ================= input validation and session storage ================= */
	$filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
		),
		'filter' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
		),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'name',
			'options' => array('options' => 'sanitize_search_string')
		),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
		),
	);

    validate_store_request_vars($filters, 'sess_wmiq');
    /* ================= input validation ================= */

	$total_rows = 0;
	$queries = array();

	if (get_request_var('rows') == '-1') {
		$rows = read_config_option('num_rows_table');
	}else{
		$rows = get_request_var('rows');
	}

	if (get_request_var('filter') != '') {
		$sql_where = 'WHERE name LIKE ' . db_qstr('%' . get_request_var('filter') . '%');
	} else {
		$sql_where = '';
	}

	query_filter();

	$queries = db_fetch_assoc("SELECT *
		FROM wmi_wql_queries
		$sql_where
		ORDER BY name
		LIMIT " . ($rows*(get_request_var('page')-1)) . ", " . $rows);

	$total_rows = sizeof($queries);

	$nav = html_nav_bar('wmi_queries.php', MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 5, __('Queries', 'wmi'), 'page', 'main');

	form_start('wmi_queries.php', 'chk');

	print $nav;

	html_start_box(__('WMI Queries', 'wmi'), '100%', '', '3', 'center', 'wmi_queries.php?action=edit');

	html_header_checkbox(
		array(
			'name' => array(
				'display' => __('Name', 'wmi'),
				'align' => 'left',
				'sort' => 'ASC'
			),
			'id' => array(
				'display' => __('ID', 'wmi'),
				'align' => 'right',
				'sort' => 'ASC'
			),
			'frequency' => array(
				'display' => __('Frequency', 'wmi'),
				'align' => 'right',
				'sort' => 'ASC'
			),
			'namespace' => array(
				'display' => __('Namespace', 'wmi'),
				'align' => 'left',
				'sort' => 'ASC'
			),
			'query' => array(
				'display' => __('WQL Query', 'wmi'),
				'align' => 'left',
				'sort' => 'ASC'
			),
			'primary_key' => array(
				'display' => __('Primary Key', 'wmi'),
				'align' => 'left',
				'sort' => 'ASC'
			)
		)
	);

	if (!empty($queries)) {
		foreach ($queries as $row) {
			form_alternate_row('line' . $row['id'], true);
			form_selectable_cell('<a class="linkEditMain" href="' . htmlspecialchars('wmi_queries.php?&action=edit&id=' . $row['id']) . '">' . $row['name'] . '</a>', $row['id']);
			form_selectable_cell($row['id'], $row['id'], '', 'right');
			form_selectable_cell($row['frequency'], $row['id'], '', 'right');
			form_selectable_cell($row['namespace'], $row['id']);
			form_selectable_cell($row['query'], $row['id']);
			form_selectable_cell($row['primary_key'], $row['id']);
			form_checkbox_cell($row['name'], $row['id']);
			form_end_row();
		}
	}

	html_end_box(false);

	if (sizeof($queries)) {
		print $nav;
	}

	draw_actions_dropdown($ds_actions);

	form_end();
}


