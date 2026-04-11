<?php

$functions = file_get_contents(dirname(__DIR__, 2) . '/functions.php');
$script    = file_get_contents(dirname(__DIR__, 2) . '/script/wmi-script.php');
$accounts  = file_get_contents(dirname(__DIR__, 2) . '/wmi_accounts.php');
$queries   = file_get_contents(dirname(__DIR__, 2) . '/wmi_queries.php');

$checks = array(
	$functions !== false && strpos($functions, "db_fetch_cell_prepared('SELECT COUNT(*) FROM wmi_wql_queries WHERE query RLIKE ?', array('^FROM\\\\s' . preg_quote(\$token, '/') . '\$+'))") !== false,
	$functions !== false && substr_count($functions, "db_fetch_row_prepared('SELECT * FROM wmi_wql_queries WHERE id = ?', array((int)\$id))") === 2,
	$script !== false && strpos($script, "db_fetch_row_prepared('SELECT * FROM plugin_wmi_queries WHERE queryname = ?', array(\$wmiquery), FALSE)") !== false,
	$accounts !== false && strpos($accounts, "html_escape(get_request_var('drp_action'))") !== false,
	$queries !== false && strpos($queries, "html_escape(get_request_var('drp_action'))") !== false,
);

foreach ($checks as $passed) {
	if (!$passed) {
		fwrite(STDERR, "WMI security wiring check failed\n");
		exit(1);
	}
}

echo "OK\n";
