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
 * Return the CSP nonce attribute for inline <script> tags, safely across
 * Cacti versions. Newer Cacti releases enforce a Content-Security-Policy that
 * requires a per-request nonce on parser-inserted scripts; older releases lack
 * the CactiSecureHeaders class, so this returns an empty string there.
 *
 * @return string The nonce attribute when supported, otherwise empty string.
 */
function plugin_wmi_csp_nonce(): string {
	if (class_exists('CactiSecureHeaders')) {
		return CactiSecureHeaders::getNonceAttribute();
	}

	return '';
}

/**
 * Installs the WMI plugin: registers its Cacti hooks (config_arrays,
 * config_form, config_settings, draw_navigation_text, api_device_save,
 * data_input_sql_where, poller_bottom, device_template_edit,
 * device_template_top, device_edit_pre_bottom, api_device_new),
 * registers its realm covering wmi_accounts.php/wmi_queries.php/
 * wmi_tools.php, and creates its database tables. Invoked by Cacti's
 * plugin architecture when an administrator installs this plugin from
 * Console > Plugin Management.
 *
 * @return void
 */
function plugin_wmi_install() {
	global $config;

	require_once($config['base_path'] . '/plugins/wmi/includes/database.php');

	api_plugin_register_hook('wmi', 'config_arrays',        'wmi_config_arrays',        'setup.php');
	api_plugin_register_hook('wmi', 'config_form',          'wmi_config_form',          'setup.php');
	api_plugin_register_hook('wmi', 'config_settings',      'wmi_config_settings',      'setup.php');
	api_plugin_register_hook('wmi', 'draw_navigation_text', 'wmi_draw_navigation_text', 'setup.php');
	api_plugin_register_hook('wmi', 'api_device_save',      'wmi_api_device_save',      'setup.php');
	api_plugin_register_hook('wmi', 'data_input_sql_where', 'wmi_data_input_sql_where', 'setup.php');
	api_plugin_register_hook('wmi', 'poller_bottom',        'wmi_poller_bottom',        'setup.php');

	api_plugin_register_hook('wmi', 'device_template_edit',   'wmi_device_template_edit',   'setup.php');
	api_plugin_register_hook('wmi', 'device_template_top',    'wmi_device_template_top',    'setup.php');
	api_plugin_register_hook('wmi', 'device_edit_pre_bottom', 'wmi_device_edit_pre_bottom', 'setup.php');
	api_plugin_register_hook('wmi', 'api_device_new',         'wmi_api_device_new',         'setup.php');

	api_plugin_register_realm('wmi', 'wmi_accounts.php,wmi_queries.php,wmi_tools.php', __('WMI Management', 'wmi'), 1);

	plugin_wmi_setup_tables();
}

/**
 * Uninstalls the WMI plugin: drops all of its database tables and
 * removes any graphs/data sources created by its two WMI Data Input
 * Methods. Invoked by Cacti's plugin architecture when an administrator
 * uninstalls this plugin from Console > Plugin Management.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to load
 *                        lib/api_data_source.php and lib/api_graph.php.
 */
function plugin_wmi_uninstall() {
	global $config;

	include_once($config['base_path'] . '/lib/api_data_source.php');
	include_once($config['base_path'] . '/lib/api_graph.php');

	db_execute('DROP TABLE IF EXISTS `wmi_processes`');
	db_execute('DROP TABLE IF EXISTS `wmi_user_accounts`');
	db_execute('DROP TABLE IF EXISTS `wmi_wql_queries`');
	db_execute('DROP TABLE IF EXISTS `host_template_wmi_query`');
	db_execute('DROP TABLE IF EXISTS `host_wmi_accounts`');
	db_execute('DROP TABLE IF EXISTS `host_wmi_query`');
	db_execute('DROP TABLE IF EXISTS `host_wmi_cache`');

	// remove graphs and data sources based upon WMI information
	$id = db_fetch_cell('SELECT GROUP_CONCAT(id)
		FROM data_input
		WHERE hash IN("4af550dfe8b451579054d038ad62ba3e","42e584b81075f6ad6556e62afc509179")');

	if ($id != '') {
		// Remove Data Sources and Graphs
		$data_sources = array_rekey(
			db_fetch_assoc('SELECT DISTINCT local_data_id
				FROM data_template_data
				WHERE local_data_id > 0
				AND data_input_id IN(' . $id . ')'),
			'local_data_id', 'local_data_id'
		);

		if (sizeof($data_sources)) {
			cacti_log('NOTE: Found ' . sizeof($data_sources) . ' WMI Data Sources during uninstall, deleting!', false, 'WMI');

			$graphs = array_rekey(
				db_fetch_assoc('SELECT
					graph_templates_graph.local_graph_id
					FROM (data_template_rrd,graph_templates_item,graph_templates_graph)
					WHERE graph_templates_item.task_item_id=data_template_rrd.id
					AND graph_templates_item.local_graph_id=graph_templates_graph.local_graph_id
					AND ' . array_to_sql_or($data_sources, 'data_template_rrd.local_data_id') . '
					AND graph_templates_graph.local_graph_id > 0
					GROUP BY graph_templates_graph.local_graph_id'),
				'local_graph_id', 'local_graph_id'
			);

			cacti_log('NOTE: Found ' . sizeof($graphs) . ' WMI Data Sources during uninstall, deleting!', false, 'WMI');

			if (sizeof($graphs)) {
				api_graph_remove_multi($graphs);
			}

			api_plugin_hook_function('graphs_remove', $graphs);

			api_data_source_remove_multi($data_sources);
		}

		// Remove any templates
	}
}

/**
 * Verifies the plugin's configuration; currently a no-op placeholder.
 * Invoked by Cacti's plugin architecture on relevant page loads.
 *
 * @return bool Always returns true.
 */
function plugin_wmi_check_config() {
	return true;
}

/**
 * Performs any schema/data migrations needed when upgrading to a newer
 * version of this plugin; currently a no-op placeholder. Invoked by
 * Cacti's plugin architecture when an installed plugin's version
 * increases.
 *
 * @return bool Always returns true.
 */
function plugin_wmi_upgrade() {
	plugin_wmi_prune_files();

	return true;
}


/**
 * Reads this plugin's INFO file and returns its [info] section. Used by
 * Cacti's plugin architecture via the api_plugin_version hook.
 *
 * @return array The parsed [info] section of the plugin's INFO file (keys
 *               such as name, version, author), or an empty array when
 *               the INFO file is missing/malformed or has no [info]
 *               section.
 *
 * @global array $config Cacti global configuration array; used to locate
 *                        the plugin's base path.
 */
function plugin_wmi_version() {
	global $config;

	$info = parse_ini_file($config['base_path'] . '/plugins/wmi/INFO', true);

	return isset($info['info']) && is_array($info['info']) ? $info['info'] : [];
}

/**
 * Hook implementation for Cacti's 'poller_bottom' filter. On the primary
 * poller, launches poller_wmi.php in master mode ('-M') as a background
 * process to poll all configured WMI devices. Called by Cacti's poller
 * via api_plugin_hook('poller_bottom', ...) at the end of each polling
 * cycle.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to locate
 *                        the PHP binary, this plugin's poller script, and
 *                        to check the current poller_id.
 */
function wmi_poller_bottom() {
	global $config;

	if ($config['poller_id'] == 1) {
		$command_string = read_config_option('path_php_binary');
		$extra_args     = '-q ' . cacti_escapeshellcmd($config['base_path'] . '/plugins/wmi/poller_wmi.php') . ' -M';
		exec_background($command_string, $extra_args);
	}
}

/**
 * Hook implementation for Cacti's 'config_arrays' filter. Registers this
 * plugin's two Data Input type constants/labels, populates the shared
 * $wmi_frequencies lookup used throughout this plugin's UI, restricts the
 * Data Query edit form's Data Input dropdown to relevant input types,
 * adds the WMI Query Tool/WMI Queries pages to Cacti's menu, and grants
 * Template Editors access to wmi_queries.php (credential management and
 * the live query tool remain restricted to the dedicated WMI Management
 * realm). Called by Cacti core via api_plugin_hook('config_arrays', ...)
 * while building the navigation menu.
 *
 * @return void
 *
 * @global array $user_auth_realms          Cacti's registered realm map
 *                                           (unused directly here;
 *                                           declared for parity with
 *                                           other config_arrays hook
 *                                           implementations).
 * @global array $user_auth_realm_filenames Cacti's realm-to-filename map
 *                                           (unused directly here;
 *                                           declared for parity with
 *                                           other config_arrays hook
 *                                           implementations).
 * @global array $menu                      Cacti's main navigation menu
 *                                           array, extended here with
 *                                           this plugin's entries.
 * @global array $input_types               Cacti's registered Data Input
 *                                           type labels, extended here
 *                                           with this plugin's two types.
 * @global array $fields_data_query_edit     The Data Query edit form's
 *                                           field definitions, narrowed
 *                                           here to relevant Data Input
 *                                           options.
 * @global array $wmi_frequencies            Populated here with this
 *                                           plugin's collection-frequency
 *                                           options.
 */
function wmi_config_arrays() {
	global $user_auth_realms, $user_auth_realm_filenames, $menu;
	global $input_types, $fields_data_query_edit, $wmi_frequencies;

	if (!defined('DATA_INPUT_TYPE_WMI')) {
		define('DATA_INPUT_TYPE_WMI', 7);
	}

	if (!defined('DATA_INPUT_TYPE_WMI_QUERY')) {
		define('DATA_INPUT_TYPE_WMI_QUERY', 8);
	}

	$input_types += [
		DATA_INPUT_TYPE_WMI       => __('WMI Data', 'wmi'),
		DATA_INPUT_TYPE_WMI_QUERY => __('WMI Data Query', 'wmi')
	];

	$wmi_frequencies = [
		'60'    => __('%d Minute',  1, 'wmi'),
		'120'   => __('%d Minutes', 2, 'wmi'),
		'300'   => __('%d Minutes', 5, 'wmi'),
		'600'   => __('%d Minutes', 10, 'wmi'),
		'1200'  => __('%d Minutes', 20, 'wmi'),
		'2400'  => __('%d Minutes', 40, 'wmi'),
		'3600'  => __('%d Hour',   1, 'wmi'),
		'7200'  => __('%d Hours',  2, 'wmi'),
		'14400' => __('%d Hours', 4, 'wmi'),
		'86400' => __('%d Day', 1, 'wmi')
	];

	$fields_data_query_edit['data_input_id']['sql'] = 'SELECT id,name FROM data_input WHERE type_id IN(3,4,6,8) ORDER BY name';

	$menu[__('Utilities')]['plugins/wmi/wmi_tools.php']         = __('WMI Query Tool', 'wmi');
	$menu[__('Data Collection')]['plugins/wmi/wmi_queries.php'] = __('WMI Queries', 'wmi');

	if (function_exists('auth_augment_roles')) {
		// Template Editors define WMI queries as part of template work. Credential
		// management (wmi_accounts.php) and the live query tool (wmi_tools.php,
		// which reaches Linux_WMI::exec) stay behind the dedicated WMI Management
		// realm rather than being handed to every Template Editor.
		auth_augment_roles(__('Template Editor'), ['wmi_queries.php']);
	}
}

/**
 * Hook implementation for Cacti's 'data_input_sql_where' filter. Excludes
 * this plugin's two special Data Input Methods from a query's Data Input
 * selection list, since they are internal implementation details rather
 * than user-selectable input methods. Called by Cacti core via
 * api_plugin_hook('data_input_sql_where', ...) while building Data Input
 * selection queries.
 *
 * @param string $sql_where The existing SQL WHERE clause fragment
 *                          contributed by Cacti core and other plugins.
 *
 * @return string The $sql_where fragment with this plugin's exclusion
 *                condition appended.
 */
function wmi_data_input_sql_where($sql_where) {
	// Exclude special data input methods
	$sql_where .= (strlen($sql_where) ? ' AND' : 'WHERE') . " (di.hash NOT IN ('4af550dfe8b451579054d038ad62ba3e', '42e584b81075f6ad6556e62afc509179'))";

	return $sql_where;
}

/**
 * Hook implementation for Cacti's 'draw_navigation_text' filter. Adds
 * breadcrumb entries for wmi_accounts.php's, wmi_queries.php's, and
 * wmi_tools.php's various views. Called by Cacti core via
 * api_plugin_hook('draw_navigation_text', ...) while rendering the page
 * breadcrumb trail.
 *
 * @param array $nav The existing breadcrumb map contributed by Cacti
 *                   core and other plugins.
 *
 * @return array The $nav array with this plugin's breadcrumb entries
 *               added.
 */
function wmi_draw_navigation_text($nav) {
	$nav['wmi_accounts.php:']        = [
		'title'   => __('WMI Authentication', 'wmi'),
		'mapping' => 'index.php:',
		'url'     => 'wmi_accounts.php',
		'level'   => '1'
	];

	$nav['wmi_accounts.php:edit'] = [
		'title'   => __('(Edit)', 'wmi'),
		'mapping' => 'index.php:wmi_accounts.php:',
		'url'     => 'wmi_accounts.php',
		'level'   => '2'
	];

	$nav['wmi_accounts.php:actions'] = [
		'title'   => __('WMI Authentication', 'wmi'),
		'mapping' => 'index.php:',
		'url'     => 'wmi_accounts.php',
		'level'   => '1'
	];

	$nav['wmi_queries.php:'] = [
		'title'   => __('WMI Queries', 'wmi'),
		'mapping' => 'index.php:',
		'url'     => 'wmi_queries.php',
		'level'   => '1'
	];

	$nav['wmi_queries.php:edit'] = [
		'title'   => __('(Edit)', 'wmi'),
		'mapping' => 'index.php:,wmi_queries.php:',
		'url'     => 'wmi_queries.php',
		'level'   => '2'
	];

	$nav['wmi_queries.php:actions'] = [
		'title'   => __('WMI Queries', 'wmi'),
		'mapping' => 'index.php:',
		'url'     => 'wmi_queries.php',
		'level'   => '2'
	];

	$nav['wmi_tools.php:'] = [
		'title'   => ('WMI Tools'),
		'mapping' => 'index.php:',
		'url'     => 'wmi_tools.php',
		'level'   => '1'
	];

	$nav['wmi_tools.php:query'] = [
		'title'   => __('(Query)', 'wmi'),
		'mapping' => 'index.php:wmi_tools.php:',
		'url'     => 'wmi_tools.php',
		'level'   => '2'
	];

	return $nav;
}

/**
 * Hook implementation for Cacti's 'config_form' filter. Adds a 'WMI
 * Account Options' spacer and a 'wmi_account' dropdown (populated from
 * wmi_user_accounts) to the Device edit form. Also intended to add a
 * 'Serial / Service Code' field, but that part of the implementation is
 * currently commented out/disabled. Called by Cacti core via
 * api_plugin_hook('config_form', ...) while building the Device edit
 * form.
 *
 * @return void
 *
 * @global array $fields_host_edit The Device edit form's field
 *                                  definitions; the 'wmi_spacer' and
 *                                  'wmi_account' fields are appended
 *                                  here.
 * @global array $plugins          Reserved/declared for parity with
 *                                  other config_form hook
 *                                  implementations; not used directly
 *                                  here.
 */
function wmi_config_form() {
	global $fields_host_edit, $plugins;

//	$fields_host_edit2 = $fields_host_edit;
//	$fields_host_edit3 = array();
//	foreach ($fields_host_edit2 as $f => $a) {
//		if ($f == 'disabled') {
//			$fields_host_edit3['serial'] = array(
//				'friendly_name' => 'Serial / Service Code',
//				'description' => 'This is the Serial Number for this server.',
//				'method' => 'textbox',
//				'max_length' => 100,
//				'value' => '|arg1:serial|',
//				'default' => '',
//			);
//		}
//		$fields_host_edit3[$f] = $a;
//	}
//	$fields_host_edit = $fields_host_edit3;

	$acc      = ['None'];
	$accounts = db_fetch_assoc('SELECT id, name FROM wmi_user_accounts ORDER BY name', false);

	if (!empty($accounts)) {
		foreach ($accounts as $a) {
			$acc[$a['id']] = $a['name'];
		}
	}

	$fields_host_edit['wmi_spacer'] = [
		'method'        => 'spacer',
		'friendly_name' => __('WMI Account Options', 'wmi')
	];

	$fields_host_edit['wmi_account'] = [
		'method'        => 'drop_array',
		'friendly_name' => __('WMI Authentication Account', 'wmi'),
		'description'   => __('Choose an account to use when Authenticating via WMI', 'wmi'),
		'value'         => '|arg1:wmi_account|',
		'default'       => 0,
		'array'         => $acc,
	];
}

/**
 * Hook implementation for Cacti's 'config_settings' filter. Registers the
 * "Misc" Settings tab's WMI fields (enable WMI collection, concurrent
 * process count, auto-create WMI queries). Called by Cacti core via
 * api_plugin_hook('config_settings', ...) while building the Settings
 * page.
 *
 * @return void
 *
 * @global array $tabs      Cacti's registered Settings page tabs,
 *                          extended here with the 'misc' tab label.
 * @global array $settings  Cacti's registered Settings page fields,
 *                          extended here with this plugin's settings
 *                          under the 'misc' tab.
 * @global array $item_rows Reserved/declared for parity with other
 *                          config_settings hook implementations; not used
 *                          directly here.
 * @global array $config    Reserved/declared for parity with other
 *                          config_settings hook implementations; not used
 *                          directly here.
 */
function wmi_config_settings() {
	global $tabs, $settings, $item_rows, $config;

	$wmi_processes = [
		1  => __('1 Process', 'wmi'),
		2  => __('%d Processes', 2, 'wmi'),
		3  => __('%d Processes', 3, 'wmi'),
		4  => __('%d Processes', 4, 'wmi'),
		5  => __('%d Processes', 5, 'wmi'),
		6  => __('%d Processes', 6, 'wmi'),
		7  => __('%d Processes', 7, 'wmi'),
		8  => __('%d Processes', 8, 'wmi'),
		9  => __('%d Processes', 9, 'wmi'),
		10 => __('%d Processes', 10, 'wmi'),
		15 => __('%d Processes', 15, 'wmi'),
		20 => __('%d Processes', 20, 'wmi'),
		25 => __('%d Processes', 25, 'wmi'),
		30 => __('%d Processes', 30, 'wmi'),
		35 => __('%d Processes', 35, 'wmi'),
		40 => __('%d Processes', 40, 'wmi'),
		45 => __('%d Processes', 45, 'wmi'),
		50 => __('%d Processes', 50, 'wmi')
	];

	$temp = [
		'wmi_header' => [
			'friendly_name' => __('WMI Settings', 'wmi'),
			'method'        => 'spacer',
			],
		'wmi_enabled' => [
			'friendly_name' => __('Enable WMI Data Collection', 'wmi'),
			'description'   => __('Check this box, if you want the WMI Plugin to query Windows devices.', 'wmi'),
			'method'        => 'checkbox',
			'default'       => 'on'
			],
		'wmi_processes' => [
			'friendly_name' => __('Concurrent Processes', 'wmi'),
			'description'   => __('How many concurrent WMI queries do you want the system to run?', 'wmi'),
			'method'        => 'drop_array',
			'default'       => '10',
			'array'         => $wmi_processes
			],
		'wmi_autocreate' => [
			'friendly_name' => __('Auto Create WMI Queries', 'wmi'),
			'description'   => __('If selected, when running either automation, or when creating/saving a Device, all WMI Queries associated with the Device Template will be created.', 'wmi'),
			'method'        => 'checkbox',
			'default'       => 'on'
		]
	];

	$tabs['misc'] = __('Misc', 'wmi');

	if (isset($settings['misc'])) {
		$settings['misc'] = array_merge($settings['misc'], $temp);
	} else {
		$settings['misc'] = $temp;
	}
}

/**
 * Hook implementation for Cacti's 'api_device_save' filter. Persists the
 * submitted 'wmi_account' selection onto the device being saved. Called
 * by Cacti core via api_plugin_hook('api_device_save', ...) before a
 * device is saved.
 *
 * @param array $save The device values being saved.
 *
 * @return array The $save array with 'wmi_account' set from the request
 *               (or 0 when not submitted).
 */
function wmi_api_device_save($save) {
	if (isset_request_var('wmi_account')) {
		$save['wmi_account'] = form_input_validate(get_filter_request_var('wmi_account'), 'wmi_account', '^[0-9]+$', false, 3);
	} else {
		$save['wmi_account'] = 0;
	}

	return $save;
}

/**
 * Hook implementation for Cacti's 'device_edit_pre_bottom' filter. Prints
 * a read-only table listing the WMI queries associated with the current
 * device's host template and whether each has already been recorded for
 * this device (in host_wmi_query). Called by Cacti core via
 * api_plugin_hook('device_edit_pre_bottom', ...) while rendering the
 * Device edit page.
 *
 * @return void Outputs HTML directly.
 */
function wmi_device_edit_pre_bottom() {
	html_start_box(__('Associated WMI Queries', 'wmi'), '100%', false, 3, 'center', '');

	$host_template_id = db_fetch_cell_prepared('SELECT host_template_id
		FROM host
		WHERE id = ?',
		[get_request_var('id')]);

	$wmi_queries = db_fetch_assoc_prepared('SELECT wwq.id, wwq.name
		FROM wmi_wql_queries AS wwq
		LEFT JOIN host_template_wmi_query AS htwq
		ON wwq.id=htwq.wmi_query_id
		WHERE htwq.host_template_id = ?
		ORDER BY name',
		[$host_template_id]);

	html_header([__('Name', 'wmi'), __('Status', 'wmi')]);

	$i = 1;

	if (sizeof($wmi_queries)) {
		foreach ($wmi_queries as $item) {
			$exists = db_fetch_cell_prepared('SELECT wmi_query_id
				FROM host_wmi_query
				WHERE host_id = ?
				AND wmi_query_id = ?',
				[get_request_var('id'), $item['id']]);

			if ($exists) {
				$exists = __('WMI Query Exists', 'wmi');
			} else {
				$exists = __('WMI Query Does Not Exist', 'wmi');
			}

			form_alternate_row("wq$i", true);
			?>
				<td class='left'>
					<strong><?php print $i; ?>)</strong> <?php print htmlspecialchars($item['name']); ?>
				</td>
				<td>
					<?php print $exists; ?>
				</td>
			<?php
			form_end_row();

			$i++;
		}
	} else {
		print '<tr><td colspan="2"><em>' . __('No Associated WMI Queries.', 'wmi') . '</em></td></tr>';
	}

	html_end_box();
}

/**
 * Hook implementation for Cacti's 'device_template_edit' filter. Prints
 * an editable table of WMI queries associated with the current device
 * template, each with a delete link, plus a dropdown/button for adding
 * an unmapped query to the template (via AJAX to host_templates.php's
 * 'item_add_wq' action). Called by Cacti core via
 * api_plugin_hook('device_template_edit', ...) while rendering the
 * Device Template edit page.
 *
 * @return void Outputs HTML and JavaScript directly.
 */
function wmi_device_template_edit() {
	html_start_box(__('Associated WMI Queries', 'wmi'), '100%', false, 3, 'center', '');

	$wmi_queries = db_fetch_assoc_prepared('SELECT wwq.id, wwq.name
		FROM wmi_wql_queries AS wwq
		INNER JOIN host_template_wmi_query AS htwq
		ON wwq.id=htwq.wmi_query_id
		WHERE htwq.host_template_id = ? ORDER BY name', [get_request_var('id')]);

	$i = 1;

	if (sizeof($wmi_queries)) {
		foreach ($wmi_queries as $item) {
			form_alternate_row("wq$i", true);
			?>
				<td class='left'>
					<strong><?php print $i; ?>)</strong> <?php print htmlspecialchars($item['name']); ?>
				</td>
				<td class='right'>
					<a class='delete deleteMarker fa fa-remove' title='<?php print __('Delete', 'wmi'); ?>' href='<?php print htmlspecialchars('host_templates.php?action=item_remove_wq_confirm&id=' . $item['id'] . '&host_template_id=' . get_request_var('id')); ?>'></a>
				</td>
			<?php
			form_end_row();

			$i++;
		}
	} else {
		print '<tr><td colspan="2"><em>' . __('No Associated WMI Queries.', 'wmi') . '</em></td></tr>';
	}

	$unmapped = db_fetch_assoc_prepared('SELECT DISTINCT wwq.id, wwq.name
		FROM wmi_wql_queries AS wwq
		LEFT JOIN host_template_wmi_query AS htwq
		ON wwq.id=htwq.wmi_query_id
		WHERE htwq.host_template_id IS NULL OR htwq.host_template_id != ?
		ORDER BY wwq.name', [get_request_var('id')]);

	if (sizeof($unmapped)) {
		?>
		<tr class='odd'>
			<td colspan='2'>
				<table>
					<tr style='line-height:10px;'>
						<td style='padding-right: 15px;'>
							<?php print __('Add WMI Query', 'wmi'); ?>
						</td>
						<td>
							<?php form_dropdown('wmi_query_id',$unmapped ,'name','id','','',''); ?>
						</td>
						<td>
							<input type='button' value='<?php print __('Add', 'wmi'); ?>' id='add_wq' title='<?php print __('Add WMI Query to Device Template', 'wmi'); ?>'>
						</td>
					</tr>
				</table>
				<script type='text/javascript' <?php print plugin_wmi_csp_nonce(); ?>>
				$('#add_wq').click(function() {
					$.post('host_templates.php?header=false&action=item_add_wq', {
						host_template_id: $('#id').val(),
						wmi_query_id: $('#wmi_query_id').val(),
						__csrf_magic: csrfMagicToken
					}).done(function(data) {
						$('div[class^="ui-"]').remove();
						$('#main').html(data);
						applySkin();
					});
				});
				</script>
			</td>
		</tr>
		<?php
	}

	html_end_box();
}

/**
 * Hook implementation for Cacti's 'device_template_top' filter. Handles
 * three device-template WMI-query actions delegated from
 * host_templates.php: 'item_remove_wq_confirm' (prints a removal
 * confirmation dialog), 'item_remove_wq' (deletes the
 * host_template_wmi_query mapping), and 'item_add_wq' (creates a new
 * mapping) - each triggered from wmi_device_template_edit()'s UI. Called
 * by Cacti core via api_plugin_hook('device_template_top', ...) at the
 * top of the Device Template edit page, before any of Cacti core's own
 * output.
 *
 * @return void For 'item_remove_wq_confirm', outputs a dialog and exits;
 *              for 'item_remove_wq'/'item_add_wq', redirects back to the
 *              template edit page and exits; otherwise returns no
 *              explicit value.
 */
function wmi_device_template_top() {
	if (get_request_var('action') == 'item_remove_wq_confirm') {
		// ================= input validation =================
		get_filter_request_var('id');
		get_filter_request_var('host_template_id');
		// ====================================================

		form_start('host_templates.php?action=edit&id' . get_request_var('host_template_id'));

		html_start_box('', '100%', false, 3, 'center', '');

		$query = db_fetch_row_prepared('SELECT * FROM wmi_wql_queries WHERE id = ?', [get_request_var('id')]);
		$query = is_array($query) ? $query : [];

		?>
		<tr>
			<td class='topBoxAlt'>
				<p><?php print __('Click \'Continue\' to delete the following WMI Queries will be disassociated from the Device Template.', 'wmi'); ?></p>
				<p><?php print __esc('WMI Query Name: %s', $query['name'] ?? '', 'wmi'); ?>'<br>
			</td>
		</tr>
		<tr>
			<td align='right'>
				<input id='cancel' type='button' value='<?php print __('Cancel', 'wmi'); ?>' onClick='$("#cdialog").dialog("close")' name='cancel'>
				<input id='continue' type='button' value='<?php print __('Continue', 'wmi'); ?>' name='continue' title='<?php print __('Remove WMI Query', 'wmi'); ?>'>
			</td>
		</tr>
		<?php

		html_end_box();

		form_end();

		?>
		<script type='text/javascript' <?php print plugin_wmi_csp_nonce(); ?>>
		$(function() {
			$('#cdialog').dialog();
		});

	    $('#continue').click(function(data) {
			$.post('host_templates.php?action=item_remove_wq', {
				__csrf_magic: csrfMagicToken,
				host_template_id: <?php print get_request_var('host_template_id'); ?>,
				id: <?php print get_request_var('id'); ?>
			}, function(data) {
				$('#cdialog').dialog('close');
				loadPageNoHeader('host_templates.php?action=edit&header=false&id=<?php print get_request_var('host_template_id'); ?>');
			});
		});
		</script>
		<?php

		exit;
	}

	if (get_request_var('action') == 'item_remove_wq') {
		// ================= input validation =================
		get_filter_request_var('id');
		get_filter_request_var('host_template_id');
		// ====================================================

		db_execute_prepared('DELETE FROM host_template_wmi_query WHERE wmi_query_id = ? AND host_template_id = ?', [get_request_var('id'), get_request_var('host_template_id')]);

		header('Location: host_templates.php?header=false&action=edit&id=' . get_request_var('host_template_id'));

		exit;
	}

	if (get_request_var('action') == 'item_add_wq') {
		// ================= input validation =================
		get_filter_request_var('host_template_id');
		get_filter_request_var('wmi_query_id');
		// ====================================================

		db_execute_prepared('REPLACE INTO host_template_wmi_query
			(host_template_id, wmi_query_id) VALUES (?, ?)',
			[get_request_var('host_template_id'), get_request_var('wmi_query_id')]);

		header('Location: host_templates.php?header=false&action=edit&id=' . get_request_var('host_template_id'));

		exit;
	}
}

/**
 * Hook implementation for Cacti's 'api_device_new' filter. Intended to
 * auto-create this device's associated WMI queries when the
 * 'wmi_autocreate' setting is enabled; currently a no-op (the
 * implementation call is commented out). Called by Cacti core via
 * api_plugin_hook('api_device_new', ...) after a new device is created.
 *
 * @param array $save The newly-created device's values, including 'id'.
 *
 * @return array The unmodified $save array (this hook does not currently
 *               modify its payload).
 *
 * @global array $config Cacti global configuration array; used to load
 *                        this plugin's functions.php.
 */
function wmi_api_device_new($save) {
	global $config;

	require_once($config['base_path'] . '/plugins/wmi/includes/functions.php');

	if (read_config_option('wmi_autocreate') == 'on') {
		if (!empty($save['id'])) {
			// wmi_autocreate($save['id']);
		}
	}

	return $save;
}

/**
 * Removes files and directories that a previous version of this plugin
 * shipped but that have since moved or been deleted, using the tombstone
 * and whitelist lists in manifest.json. Whitelisted (user-data) paths and
 * any VCS metadata (.git*) are never touched; the dev-only tests/ tree is
 * removed. Any path that resolves outside the plugin directory (a tampered
 * manifest.json) is refused, and any file/directory that cannot be removed
 * (e.g. read-only) is reported to the Cacti log. Any top-level entry that is
 * neither expected nor a tombstone nor whitelisted is logged to the Cacti
 * log and left in place. Called on a plugin version change.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to resolve
 *                       the plugin directory.
 */
function plugin_wmi_prune_files(): void {
	global $config;

	$plugin_dir    = $config['base_path'] . '/plugins/wmi';
	$manifest_path = $plugin_dir . '/manifest.json';

	if (!is_readable($manifest_path)) {
		return;
	}

	$manifest = json_decode((string) file_get_contents($manifest_path), true);

	if (!is_array($manifest)) {
		cacti_log('WARNING: wmi manifest.json could not be parsed; skipping file prune', false, 'WMI');

		return;
	}

	$tombstones = isset($manifest['tombstones']) && is_array($manifest['tombstones']) ? $manifest['tombstones'] : [];
	$expected   = isset($manifest['expected'])   && is_array($manifest['expected'])   ? $manifest['expected']   : [];
	$whitelist  = isset($manifest['whitelist'])  && is_array($manifest['whitelist'])  ? $manifest['whitelist']  : [];

	$protected = function (string $rel) use ($whitelist): bool {
		if (strncmp($rel, '.git', 4) === 0 || strncmp($rel, '.md', 3) === 0) {
			return true;
		}

		foreach ($whitelist as $entry) {
			$entry = trim((string) $entry, '/');

			if ($entry !== '' && ($rel === $entry
				|| strncmp($rel, $entry . '/', strlen($entry) + 1) === 0
				|| strncmp($entry, $rel . '/', strlen($rel) + 1) === 0)) {
				return true;
			}
		}

		return false;
	};

	// Security: resolve the plugin directory so a tampered manifest.json
	// cannot steer the prune outside of it.
	$plugin_real = realpath($plugin_dir);

	// Remove tombstoned (moved/deleted) paths plus the dev-only tests/
	// tree and the phpunit.xml test configuration.
	$remove   = $tombstones;
	$remove[] = 'tests/';
	$remove[] = 'phpunit.xml';

	foreach ($remove as $rel) {
		$rel = trim((string) $rel, '/');

		if ($rel === '' || $protected($rel)) {
			continue;
		}

		// A tombstone must never contain '.'/'..' segments; a tampered manifest
		// could use them to escape the plugin directory or target its root.
		$segments = explode('/', $rel);

		if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
			cacti_log(sprintf('WARNING: wmi prune refused to remove %s: path contains a traversal segment (tampered manifest.json?)', $rel), false, 'WMI');

			continue;
		}

		$path = $plugin_dir . '/' . $rel;

		if (!is_link($path) && !file_exists($path)) {
			continue;
		}

		// Refuse any path that, after resolving symlinks and ../ segments,
		// escapes the plugin directory (protects user data from a tampered
		// manifest.json).
		$anchor = is_link($path) ? dirname($path) : $path;
		$real   = realpath($anchor);

		if ($real === false || ($real !== $plugin_real && strncmp($real, $plugin_real . DIRECTORY_SEPARATOR, strlen((string) $plugin_real) + 1) !== 0)) {
			cacti_log(sprintf('WARNING: wmi prune refused to remove %s: path resolves outside the plugin directory (tampered manifest.json?)', $rel), false, 'WMI');

			continue;
		}

		if (is_dir($path) && !is_link($path)) {
			$removed = plugin_wmi_rmtree($path);
		} else {
			$removed = @unlink($path);
		}

		if (!$removed) {
			cacti_log(sprintf('WARNING: wmi upgrade could not remove %s (check file/directory permissions)', $rel), false, 'WMI');
		}
	}

	// Surface any top-level entry the manifest does not account for.
	$known = [];

	foreach (array_merge($expected, $tombstones) as $entry) {
		$top = explode('/', trim((string) $entry, '/'))[0];

		if ($top !== '') {
			$known[$top] = true;
		}
	}

	$entries = scandir($plugin_dir);

	foreach (($entries !== false ? $entries : []) as $entry) {
		if ($entry === '.' || $entry === '..' || $entry === 'tests' || $entry === 'phpunit.xml' || $protected($entry) || isset($known[$entry])) {
			continue;
		}

		cacti_log(sprintf('WARNING: wmi upgrade found a file/directory not described in manifest.json: %s (left in place)', $entry), false, 'WMI');
	}
}

/**
 * Recursively deletes a directory and its contents. Symlinks are removed
 * without being followed. Helper for plugin_wmi_prune_files().
 *
 * @param string $dir Absolute path to the directory to remove.
 *
 * @return bool True if the directory and everything under it was removed;
 *              false if any entry could not be deleted.
 */
function plugin_wmi_rmtree(string $dir): bool {
	$entries = scandir($dir);
	$ok      = true;

	foreach (($entries !== false ? $entries : []) as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}

		$path = $dir . '/' . $entry;

		if (is_dir($path) && !is_link($path)) {
			if (!plugin_wmi_rmtree($path)) {
				$ok = false;
			}
		} elseif (!@unlink($path)) {
			$ok = false;
		}
	}

	if (!@rmdir($dir)) {
		$ok = false;
	}

	return $ok;
}
