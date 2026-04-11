<?php

$checks = array(
	'functions.php' => array(
		"db_fetch_cell_prepared('SELECT COUNT(*) FROM wmi_wql_queries WHERE query RLIKE ?', array('^FROM\\\\s' . preg_quote(\$token, '/') . '\$+'))",
		"db_fetch_row_prepared('SELECT * FROM wmi_wql_queries WHERE id = ?', array((int)\$id))",
	),
	'script/wmi-script.php' => array(
		"db_fetch_row_prepared('SELECT * FROM plugin_wmi_queries WHERE queryname = ?', array(\$wmiquery), FALSE)",
	),
	'wmi_accounts.php' => array(
		"html_escape(db_fetch_cell_prepared('SELECT name",
		"html_escape(get_request_var('drp_action'))",
	),
	'wmi_queries.php' => array(
		"html_escape(db_fetch_cell_prepared('SELECT name",
		"html_escape(get_request_var('drp_action'))",
	),
);

foreach ($checks as $file => $needles) {
	$source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);

	if ($source === false) {
		fwrite(STDERR, "Unable to read $file\n");
		exit(1);
	}

	foreach ($needles as $needle) {
		if (strpos($source, $needle) === false) {
			fwrite(STDERR, "Missing expected guard in $file\n");
			exit(1);
		}
	}
}

echo "OK\n";
