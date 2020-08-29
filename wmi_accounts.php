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

$account_actions = array(
	1 => __('Delete', 'wmi')
);

set_default_action();

$password = get_request_var('password');
$username = get_request_var('username');

if (!isset_request_var('tab')) {
	set_request_var('tab', 'accounts');
}

$account_edit = array(
	'name' => array(
		'method' => 'textbox',
		'friendly_name' => __('Name', 'wmi'),
		'description' => __('Give this account a meaningful name that will be displayed.', 'wmi'),
		'value' => '|arg1:name|',
		'max_length' => '64',
		),
	'username' => array(
		'method' => 'textbox',
		'friendly_name' => __('Username', 'wmi'),
		'description' => __('The username that will be used for authentication.  Please also include the domain if necessary.', 'wmi'),
		'value' => '|arg1:username|',
		'max_length' => '64',
		),
	'password' => array(
		'method' => 'textbox_password',
		'friendly_name' => __('Password', 'wmi'),
		'description' => __('The password used for authentication.', 'wmi'),
		'value' => '|arg1:password|',
		'default' => '',
		'max_length' => '64',
		'size' => '30'
		),
	'id' => array(
		'method' => 'hidden_zero',
		'value' => '|arg1:id|'
		)
);

switch (get_request_var('action')) {
	case 'actions':
		actions_accounts();
		break;
	case 'save':
		save_accounts ();
		break;
	case 'edit':
		top_header();
		display_tabs ();
		edit_accounts();
		bottom_footer();
		break;
	default:
		top_header();
		display_tabs ();
		show_accounts ();
		bottom_footer();
		break;
}

function actions_accounts() {
	global $account_actions;

	/* ================= input validation ================= */
	get_filter_request_var('drp_action');
	/* ==================================================== */

	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			if (get_request_var('drp_action') == '1') {
				for ($i=0; $i<count($selected_items); $i++) {
					db_execute_prepared('DELETE FROM wmi_user_accounts
						WHERE id = ?',
						array($selected_items[$i]));

					db_execute_prepared('UPDATE host
						SET wmi_account = 0
						WHERE wmi_account = ?',
						array($selected_items[$i]));
				}
			}

			header('Location: wmi_accounts.php');
			exit;
		}
	}


	/* setup some variables */
	$account_list = '';

	/* loop through each of the accounts selected on the previous page and get more info about them */
	foreach ($_POST as $var => $val){
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$account_list .= '<li>' . db_fetch_cell_prepared('SELECT name
				FROM wmi_user_accounts
				WHERE id = ?',
				array($matches[1])) . '</li>';

			$account_array[] = $matches[1];
		}
	}

	top_header();

	form_start('wmi_accounts.php');

	html_start_box($account_actions[get_request_var('drp_action')], '60%', '', '3', 'center', '');


	if (get_nfilter_request_var('drp_action') == '1') { /* Delete */
		print "<tr>
			<td colspan='2' class='textArea'>
				<p>" . __('Press \'Continue\' to delete the following accounts.', 'wmi') . "</p>
				<div class='itemlist'><ul>" . $account_list . "</ul></div>
			</td>
		</tr>";
	}

	if (!isset($account_array)) {
		print "<tr><td class='odd'><span class='textError'>" . __('You must select at least one account.', 'wmi') . "</span></td></tr>";
		$save_html = "";
	}else{
		$save_html = "<input type='submit' value='" . __('Continue', 'wmi') . "'>";
	}

	print "<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($account_array) ? serialize($account_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . get_request_var('drp_action') . "'>
			<input type='button' value='" . __('Cancel', 'wmi') . "' onClick='cactiReturnTo()'>
			$save_html
		</td>
	</tr>";

	html_end_box();

	bottom_footer();
}

function save_accounts() {
	$save['id']       = get_filter_request_var('id');
	$save['name']     = get_nfilter_request_var('name');
	$save['username'] = get_nfilter_request_var('username');

	if (get_nfilter_request_var('password') == get_nfilter_request_var('password_confirm')) {
		if (get_nfilter_request_var('password') != '') {
			$wmi = new Linux_WMI();

			$save['password'] = $wmi->encode(get_nfilter_request_var('password'));
		} else if ($save['id'] < 1) {
			raise_message(4);
		}
	} else {
		raise_message(4);
	}

	$id = sql_save($save, 'wmi_user_accounts', 'id');

	if (is_error_message()) {
		header('Location: wmi_accounts.php?header=false&action=edit&id=' . (empty($id) ? get_request_var('id') : $id));
		exit;
	}

	header('Location: wmi_accounts.php?header=false');

	exit;
}

function edit_accounts() {
	global $account_edit;

	/* ================= input validation ================= */
	get_filter_request_var('id');
	/* ==================================================== */

	$account = array();
	if (!isempty_request_var('id')) {
		$account = db_fetch_row_prepared('SELECT * FROM wmi_user_accounts WHERE id = ?', array(get_request_var('id')));

		$account['password'] = '';
		$header_label = __('Account [edit: %s]', $account['name'], 'wmi');
	}else{
		$header_label = __('Account [new]', 'wmi');
	}

	form_start('wmi_accounts.php?tab=accounts', 'chk');

	html_start_box($header_label, '100%', '', '3', 'center', '');
	draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => inject_form_variables($account_edit, $account)
		)
	);

	html_end_box();

	form_save_button('wmi_accounts.php');
}

function account_filter() {
	global $item_rows;

	html_start_box( __('WMI Accounts', 'wmi'), '100%', '', '3', 'center', 'wmi_accounts.php?action=edit');
	?>
	<tr class='even'>
		<td>
			<form id='form_wmi' action='accounts.php'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'wmi');?>
					</td>
					<td>
						<input type='text' id='filter' size='25' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Accounts', 'wmi');?>
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
						<input type='button' Value='<?php print __x('filter: use', 'Go', 'wmi');?>' id='refresh'>
					</td>
					<td>
						<input type='button' Value='<?php print __x('filter: reset', 'Clear', 'wmi');?>' id='clear'>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' value='<?php print get_filter_request_var('page');?>'>
			</form>
			<script type='text/javascript'>

			function applyFilter() {
				strURL = 'wmi_accounts.php?filter='+$('#filter').val()+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&has_graphs='+$('#has_graphs').is(':checked')+'&header=false';
				loadPageNoHeader(strURL);
			}

			function clearFilter() {
				strURL = 'wmi_accounts.php?clear=1&header=false';
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

function show_accounts() {
	global $host, $username, $password, $command;
	global $account_actions;

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

    validate_store_request_vars($filters, 'sess_wmia');
    /* ================= input validation ================= */

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

	$accounts = db_fetch_assoc("SELECT *
		FROM wmi_user_accounts
		$sql_where
		ORDER BY name
		LIMIT " . ($rows*(get_request_var('page')-1)) . ', ' . $rows);

	$total_rows = db_fetch_cell('SELECT COUNT(*) FROM wmi_user_accounts');

	account_filter();

    $nav = html_nav_bar('wmi_accounts.php?tab=accounts', MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 5, __('Accounts', 'wmi'), 'page', 'main');

    form_start('wmi_accounts.php?tab=accounts', 'chk');

    print $nav;

    html_start_box('', '100%', '', '3', 'center', 'wmi_accounts.php?action=edit');

	$display_text = array(
		'name' => array(
			'display' => __('Description', 'wmi'),
			'order' => 'ASC',
			'align' => 'left'
		),
		'username' => array(
			'display' => __('Username', 'wmi'),
			'order' => 'ASC',
			'align' => 'left'
		),
		'nosort' => array(
			'display' => __('Devices', 'wmi'),
			'order' => 'DESC',
			'align' => 'right'
		)
	);

	html_header_checkbox($display_text);

	if (sizeof($accounts)) {
		foreach ($accounts as $row) {
			$count = db_fetch_cell_prepared("SELECT COUNT(wmi_account)
				FROM host
				WHERE wmi_account = ?", array($row['id']));

			form_alternate_row('line' . $row['id'], false);
			form_selectable_cell(filter_value($row['name'], get_request_var('filter'), 'wmi_accounts.php?&action=edit&id=' . $row['id']), $row['id']);
			form_selectable_cell($row['username'], $row['id']);
			form_selectable_cell(number_format_i18n($count), $row['id'], '', 'right');
			form_checkbox_cell($row['name'], $row['id']);
			form_end_row();
		}
	}else{
		print "<tr class='tableRow'><td colspan='4'><em>" . __('No Accounts Found', 'wmi') . "</em></td></tr>";
	}

	html_end_box(false);

	if (sizeof($accounts)) {
		print $nav;
	}

	draw_actions_dropdown($account_actions);

	form_end();
}

