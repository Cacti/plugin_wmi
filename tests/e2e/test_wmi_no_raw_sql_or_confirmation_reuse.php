<?php

$files = array(
	'functions.php',
	'script/wmi-script.php',
	'wmi_accounts.php',
	'wmi_queries.php',
);

$legacy_needles = array(
	'db_fetch_row("SELECT * FROM wmi_wql_queries WHERE id = $id")',
	'db_fetch_row("SELECT * FROM plugin_wmi_queries WHERE queryname = \'$wmiquery\'", FALSE)',
	'db_fetch_cell("SELECT COUNT(*) FROM wmi_wql_queries WHERE query RLIKE \'^FROM\\s$token$+\'")',
	"<input type='hidden' name='drp_action' value='\" . get_request_var('drp_action') . \"'>",
	"db_fetch_cell_prepared('SELECT name\n\t\t\t\tFROM wmi_user_accounts\n\t\t\t\tWHERE id = ?',\n\t\t\t\t[\$matches[1]]) . '</li>'",
	"db_fetch_cell_prepared('SELECT name\n\t\t\t\tFROM wmi_wql_queries\n\t\t\t\tWHERE id = ?',\n\t\t\t\t[\$matches[1]]) . '</li>'",
);

foreach ($files as $file) {
	$source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);

	if ($source === false) {
		fwrite(STDERR, "Unable to read $file\n");
		exit(1);
	}

	foreach ($legacy_needles as $needle) {
		if (strpos($source, $needle) !== false) {
			fwrite(STDERR, "Found legacy insecure pattern in $file\n");
			exit(1);
		}
	}
}

echo "OK\n";
