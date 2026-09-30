<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/**
 * Adds the 'wmi_account' column to Cacti's host table, creates this
 * plugin's database tables (wmi_user_accounts, wmi_wql_queries,
 * host_wmi_query, host_template_wmi_query, host_wmi_accounts,
 * host_wmi_cache, wmi_processes), and registers this plugin's two Data
 * Input Methods ('Get WMI Data' and 'Get WMI Data (Indexed)') along with
 * their input/output fields, if not already present. Called from
 * plugin_wmi_install() during plugin installation.
 *
 * @return void
 */
function plugin_wmi_setup_tables() {
	api_plugin_db_add_column('wmi', 'host',
		[
			'name'     => 'wmi_account',
			'type'     => 'int(10)',
			'unsigned' => true,
			'NULL'     => false,
			'default'  => '0',
			'after'    => 'disabled'
		]
	);

	db_execute("CREATE TABLE IF NOT EXISTS `wmi_user_accounts` (
		`id` int(11) UNSIGNED NOT NULL auto_increment,
		`name` varchar(64) NOT NULL,
		`username` varchar(64) NOT NULL,
		`password` varchar(256) NOT NULL,
		PRIMARY KEY (`id`))
		ENGINE=InnoDB
		COMMENT='Holds Account Information for WMI Queries'");

	db_execute("CREATE TABLE IF NOT EXISTS `wmi_wql_queries` (
		`id` int(11) UNSIGNED NOT NULL auto_increment,
		`hash` varchar(32) NOT NULL default '',
		`name` varchar(64) NOT NULL,
		`frequency` mediumint(8) unsigned NOT NULL DEFAULT '86400',
		`enabled` char(2) DEFAULT 'on',
		`namespace` varchar(64) NOT NULL,
		`query` varchar(1024) NOT NULL,
		`primary_key` varchar(128) NOT NULL DEFAULT 'None',
		PRIMARY KEY (`id`))
		ENGINE=InnoDB
		COMMENT='Holds WMI Queries for Devices'");

	db_execute("CREATE TABLE IF NOT EXISTS `host_wmi_query` (
		`host_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		`wmi_query_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		`sort_field` varchar(50) NOT NULL DEFAULT '',
		`title_format` varchar(50) NOT NULL DEFAULT '',
		`last_started` timestamp NOT NULL DEFAULT '0000-00-00',
		`last_runtime` double NOT NULL DEFAULT '0.00',
		`last_failed` timestamp NOT NULL DEFAULT '0000-00-00',
		PRIMARY KEY (`host_id`,`wmi_query_id`))
		ENGINE=InnoDB
		COMMENT='Holds WMI Data Queries'");

	db_execute("CREATE TABLE IF NOT EXISTS `host_template_wmi_query` (
		`host_template_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		`wmi_query_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		PRIMARY KEY (`host_template_id`,`wmi_query_id`))
		ENGINE=InnoDB
		COMMENT='Holds Device Template WMI Queries'");

	db_execute("CREATE TABLE IF NOT EXISTS `host_wmi_accounts` (
		`id` int(11) UNSIGNED NOT NULL auto_increment,
		`host_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		`account_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		PRIMARY KEY (`id`),
		KEY `host_id` (`host_id`))
		ENGINE=InnoDB
		COMMENT='Holds Device WMI Accounts'");

	db_execute("CREATE TABLE IF NOT EXISTS `host_wmi_cache` (
		`host_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		`wmi_query_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		`field_name` varchar(50) NOT NULL DEFAULT '',
		`field_value` varchar(4096) DEFAULT NULL,
		`wmi_index` varchar(255) NOT NULL DEFAULT '',
		`present` tinyint(4) NOT NULL DEFAULT '1',
		`last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (`host_id`,`wmi_query_id`,`field_name`,`wmi_index`),
		KEY `host_id` (`host_id`,`field_name`),
		KEY `wmi_index` (`wmi_index`),
		KEY `field_name` (`field_name`),
		KEY `field_value` (`field_value`),
		KEY `wim_query_id` (`wmi_query_id`),
		KEY `present` (`present`),
		KEY `last_updated` (`last_updated`))
		ENGINE=InnoDB
		COMMENT='Holds Device WMI Information'");

	db_execute("CREATE TABLE IF NOT EXISTS `wmi_processes` (
		`pid` int(10) unsigned NOT NULL,
		`taskid` int(10) unsigned NOT NULL,
		`started` timestamp NOT NULL default CURRENT_TIMESTAMP,
		PRIMARY KEY  (`pid`))
		ENGINE=MEMORY
		COMMENT='Running wmi collector processes';");

	$exists = db_fetch_cell('SELECT id FROM data_input WHERE hash="4af550dfe8b451579054d038ad62ba3e"');

	if (!$exists) {
		$save                 = [];
		$save['hash']         = '4af550dfe8b451579054d038ad62ba3e';
		$save['name']         = 'Get WMI Data';
		$save['input_string'] = '';
		$save['type_id']      = 7;
		$id                   = sql_save($save, 'data_input');

		if ($id) {
			db_execute("INSERT INTO `data_input_fields`
				(hash, data_input_id, name, data_name, input_output, update_rra, sequence, type_code, regexp_match, allow_nulls)
				VALUES ('e45cfa73589b88887725350a728d2ee9',$id,'The WMI Class Name','class','in','',0,'','','')");

			db_execute("INSERT INTO `data_input_fields`
				(hash, data_input_id, name, data_name, input_output, update_rra, sequence, type_code, regexp_match, allow_nulls)
				VALUES ('c5f782d783edec607f64bea9cccd533c',$id,'The WMI Column Name','column','in','',0,'','','')");
		}
	}

	$exists = db_fetch_cell('SELECT id
		FROM data_input
		WHERE hash="42e584b81075f6ad6556e62afc509179"');

	if (!$exists) {
		$save                 = [];
		$save['hash']         = '42e584b81075f6ad6556e62afc509179';
		$save['name']         = 'Get WMI Data (Indexed)';
		$save['input_string'] = '';
		$save['type_id']      = 8;
		$id                   = sql_save($save, 'data_input');

		if ($id) {
			db_execute("INSERT INTO `data_input_fields`
				(hash, data_input_id, name, data_name, input_output, update_rra, sequence, type_code, regexp_match, allow_nulls)
				VALUES ('fb6317f2c49e494007e968283576d5a8',$id,'The WMI Class Name','class','in','',0,'','','')");

			db_execute("INSERT INTO `data_input_fields`
				(hash, data_input_id, name, data_name, input_output, update_rra, sequence, type_code, regexp_match, allow_nulls)
				VALUES ('cfebf9aa08f98bc1bfda7de2ebe12d94',$id,'The WMI Column Name','column','in','',0,'','','')");

			db_execute("INSERT INTO `data_input_fields`
				(hash, data_input_id, name, data_name, input_output, update_rra, sequence, type_code, regexp_match, allow_nulls)
				VALUES ('41798400f48141c25bc2407b5f5b1573',$id,'Output Type ID','output_type','in','',0,'output_type','','')");

			db_execute("INSERT INTO `data_input_fields`
				(hash, data_input_id, name, data_name, input_output, update_rra, sequence, type_code, regexp_match, allow_nulls)
				VALUES ('02cd18a75a17e0a7d4ca28bc224630e0',$id,'Output Value','output','out','on',0,'','','')");
		}
	}
}
