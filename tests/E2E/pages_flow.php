<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Drives the WMI Authentication, WMI Queries and WMI Query Tool pages and the
 * Device Template hook through real HTTP requests to PHP's built-in server,
 * against a real Cacti installation and database, with a fake wmic. Run by
 * PagesFlowTest.php in its own process; prints one JSON document of
 * observations on the last line.
 *
 * tests/ ships inside the plugin directory, so this file must do nothing when
 * a web server runs it. It needs the CLI and a work directory that the test
 * process names and creates; it takes no path or command from a request.
 */
$work = getenv('WMI_E2E_WORKDIR');

if (PHP_SAPI !== 'cli' || getenv('WMI_E2E') !== '1' || $work === false || !is_dir($work)) {
	http_response_code(404);

	exit;
}

require_once __DIR__ . '/e2e_common.php';

wmi_e2e_boot();

const USER_ADMIN  = 900001;
const USER_NOWMI  = 900002;
const USER_NONE   = 900003;
const PW          = 'E2e-pass-1';
const WMI_REALM   = 102;
const CONSOLE     = 8;

$out = [];

// Realm 102 is the plugin's own: look it up instead of assuming the number.
$wmi_realm = (int) db_fetch_cell_prepared("SELECT id + 100 FROM plugin_realms WHERE plugin = 'wmi' LIMIT 1");

$out['realm_found'] = $wmi_realm > 100;

$admin_realms = array_map('intval', array_column(db_fetch_assoc_prepared('SELECT realm_id FROM user_auth_realm WHERE user_id = 1'), 'realm_id'));

if (!in_array($wmi_realm, $admin_realms, true)) {
	$admin_realms[] = $wmi_realm;
}

wmi_e2e_add_user(USER_ADMIN, 'wmie2e_admin', PW, $admin_realms);
wmi_e2e_add_user(USER_NOWMI, 'wmie2e_nowmi', PW, [CONSOLE]);

$server = wmi_e2e_start_server();

register_shutdown_function(function () use ($server) {
	wmi_e2e_stop_server($server);
});

$anon  = new Wmi_E2e_Http($server['base']);
$admin = new Wmi_E2e_Http($server['base']);
$nowmi = new Wmi_E2e_Http($server['base']);

$out['admin_login'] = $admin->login('wmie2e_admin', PW);
$out['nowmi_login'] = $nowmi->login('wmie2e_nowmi', PW);
$out['bad_login']   = (new Wmi_E2e_Http($server['base']))->login('wmie2e_admin', 'wrong');

$pages = ['accounts' => 'plugins/wmi/wmi_accounts.php', 'queries' => 'plugins/wmi/wmi_queries.php', 'tools' => 'plugins/wmi/wmi_tools.php'];

// Access control: anonymous users are sent to the login, users without the
// WMI realm are refused, the administrator is served.
foreach ($pages as $label => $path) {
	$a = $anon->request($path);
	$n = $nowmi->request($path);
	$s = $admin->request($path);

	$out['access_' . $label] = [
		'anon_code'     => $a['code'],
		'anon_location' => $a['location'],
		'anon_login_form' => strpos($a['body'], 'login_username') !== false,
		'anon_sees_page' => strpos($a['body'], 'wmi_') !== false && strpos($a['body'], 'login_username') === false,
		'nowmi_denied'  => stripos($n['body'], 'permission') !== false || stripos($n['body'], 'denied') !== false || $n['code'] >= 400,
		'nowmi_has_form' => strpos($n['body'], 'wmi_') !== false && strpos($n['body'], 'Authentication') !== false && stripos($n['body'], 'denied') === false,
		'admin_code'    => $s['code'],
	];
}

/** Number of accounts with a given name. */
$count_accounts = fn (string $name): int => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM wmi_user_accounts WHERE name = ?', [$name]);
$account_by     = fn (string $name): array => db_fetch_row_prepared('SELECT * FROM wmi_user_accounts WHERE name = ?', [$name]) ?: [];
$decode         = fn (array $row): string => $row === [] ? '' : (new Linux_WMI())->decode($row['password']);

$save_account = function (array $fields, bool $csrf = true) use ($admin, $pages): array {
	$page = $admin->page($pages['accounts'], ['action' => 'edit']);

	return $admin->post($pages['accounts'], $fields + ['action' => 'save'], $csrf ? $page['token'] : '');
};

$base_fields = ['id' => 0, 'name' => 'e2e-acct-1', 'username' => 'DOM\\svc', 'password' => 'pw-one', 'password_confirm' => 'pw-one'];

$r = $save_account($base_fields);

$out['acct_create'] = ['code' => $r['code'], 'location' => $r['location'], 'count' => $count_accounts('e2e-acct-1'), 'username' => $account_by('e2e-acct-1')['username'] ?? '', 'password' => $decode($account_by('e2e-acct-1'))];

$acct1 = (int) ($account_by('e2e-acct-1')['id'] ?? 0);

$rejections = [
	'mismatch'       => ['name' => 'e2e-rej-mismatch', 'password_confirm' => 'different'],
	'empty_password' => ['name' => 'e2e-rej-empty', 'password' => '', 'password_confirm' => ''],
	'empty_name'     => ['name' => ''],
	'empty_username' => ['name' => 'e2e-rej-user', 'username' => ''],
	'too_long'       => ['name' => 'e2e-rej-long', 'password' => str_repeat('p', 200), 'password_confirm' => str_repeat('p', 200)],
];

foreach ($rejections as $label => $change) {
	$fields = $change + $base_fields;
	$r      = $save_account($fields);

	$out['acct_reject_' . $label] = ['code' => $r['code'], 'location' => $r['location'], 'count' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM wmi_user_accounts WHERE name = ?', [$fields['name']])];
}

// Boundary: a 64 character password is accepted and round-trips.
$long = str_repeat('q', 64);
$r    = $save_account(['name' => 'e2e-acct-64', 'password' => $long, 'password_confirm' => $long] + $base_fields);

$out['acct_64'] = ['count' => $count_accounts('e2e-acct-64'), 'password_ok' => $decode($account_by('e2e-acct-64')) === $long, 'stored_length' => strlen($account_by('e2e-acct-64')['password'] ?? '')];

// Editing keeps the stored password when the field is left blank, and
// replaces it when a new one is confirmed.
$save_account(['id' => $acct1, 'name' => 'e2e-acct-1b', 'password' => '', 'password_confirm' => ''] + $base_fields);
$out['acct_edit_blank'] = ['renamed' => $count_accounts('e2e-acct-1b'), 'password' => $decode($account_by('e2e-acct-1b'))];

$save_account(['id' => $acct1, 'name' => 'e2e-acct-1b', 'password' => 'pw-two', 'password_confirm' => 'pw-two'] + $base_fields);
$out['acct_edit_new_password'] = ['password' => $decode($account_by('e2e-acct-1b'))];

$save_account(['id' => $acct1, 'name' => 'e2e-acct-1b', 'password' => 'pw-three', 'password_confirm' => 'oops'] + $base_fields);
$out['acct_edit_mismatch'] = ['password' => $decode($account_by('e2e-acct-1b')), 'name' => $account_by('e2e-acct-1b')['name'] ?? ''];

// Without a CSRF token, or by GET, nothing is written.
$r = $save_account(['name' => 'e2e-acct-nocsrf'] + $base_fields, false);
$out['acct_no_csrf'] = ['code' => $r['code'], 'count' => $count_accounts('e2e-acct-nocsrf')];

$r = $admin->request($pages['accounts'], ['action' => 'save', 'name' => 'e2e-acct-get'] + $base_fields);
$out['acct_get_save'] = ['code' => $r['code'], 'count' => $count_accounts('e2e-acct-get')];

// The list: the search filter and the pager total count matching rows.
foreach (['e2e-acct-x1' => 51, 'e2e-acct-x2' => 52, 'e2e-other' => 53] as $name => $offset) {
	wmi_e2e_add_account(WMI_E2E_ID + $offset, $name, 'u', 'p');
}

$total_accounts = (int) db_fetch_cell('SELECT COUNT(*) FROM wmi_user_accounts');
$matching       = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM wmi_user_accounts WHERE name LIKE ?', ['%e2e-acct-x%']);

$list = $admin->request($pages['accounts'], ['filter' => 'e2e-acct-x', 'rows' => 1, 'page' => 1]);

$out['acct_list_filtered'] = ['matching' => $matching, 'total' => $total_accounts, 'nav' => preg_match('/of (\d+)/', strip_tags($list['body']), $m) ? (int) $m[1] : -1, 'has_x1' => strpos($list['body'], 'e2e-acct-x1') !== false, 'has_other' => strpos($list['body'], 'e2e-other') !== false];

// Bulk delete: confirmation page, then the delete itself.
$ids  = array_column(db_fetch_assoc_prepared('SELECT id FROM wmi_user_accounts WHERE name LIKE ?', ['e2e-acct-x%']), 'id');
$page = $admin->page($pages['accounts']);
$conf = $admin->post($pages['accounts'], ['action' => 'actions', 'drp_action' => 1, 'chk_' . $ids[0] => 'on', 'chk_' . $ids[1] => 'on'], $page['token']);

$out['acct_delete_confirm'] = ['code' => $conf['code'], 'lists_names' => strpos($conf['body'], 'e2e-acct-x1') !== false || strpos($conf['body'], 'e2e-acct-x2') !== false];

$del = $admin->post($pages['accounts'], ['action' => 'actions', 'drp_action' => 1, 'selected_items' => serialize(array_map('strval', $ids))], $page['token']);

$out['acct_delete'] = ['code' => $del['code'], 'left' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM wmi_user_accounts WHERE name LIKE ?', ['e2e-acct-x%'])];

// Queries.
$qlist = fn (array $q) => $admin->request($pages['queries'], $q);

$save_query = function (array $fields, bool $csrf = true) use ($admin, $pages): array {
	$page = $admin->page($pages['queries'], ['action' => 'edit']);

	return $admin->post($pages['queries'], $fields + ['action' => 'save'], $csrf ? $page['token'] : '');
};

$query_fields = ['id' => 0, 'name' => 'e2eq-new', 'frequency' => 300, 'enabled' => 'on', 'namespace' => 'root\\\\CIMV2', 'query' => 'SELECT Name FROM Win32_Service', 'primary_key' => 'Name'];

$r = $save_query($query_fields);
$row = db_fetch_row_prepared('SELECT * FROM wmi_wql_queries WHERE name = ?', ['e2eq-new']) ?: [];

$out['query_create'] = ['code' => $r['code'], 'location' => $r['location'], 'row' => $row === [] ? [] : array_intersect_key($row, array_flip(['name', 'frequency', 'enabled', 'namespace', 'query', 'primary_key'])), 'hash_len' => strlen($row['hash'] ?? '')];

$r = $save_query(['id' => (int) ($row['id'] ?? 0), 'name' => 'e2eq-new', 'frequency' => 600, 'enabled' => ''] + $query_fields);
$row2 = db_fetch_row_prepared('SELECT * FROM wmi_wql_queries WHERE name = ?', ['e2eq-new']) ?: [];

$out['query_edit'] = ['frequency' => $row2['frequency'] ?? '', 'enabled' => $row2['enabled'] ?? 'x', 'same_hash' => ($row2['hash'] ?? 'a') === ($row['hash'] ?? 'b')];

$r = $save_query(['name' => 'e2eq-nocsrf'] + $query_fields, false);
$out['query_no_csrf'] = ['code' => $r['code'], 'count' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM wmi_wql_queries WHERE name = ?', ['e2eq-nocsrf'])];

$r = $admin->request($pages['queries'], ['action' => 'save', 'name' => 'e2eq-get'] + $query_fields);
$out['query_get_save'] = ['code' => $r['code'], 'count' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM wmi_wql_queries WHERE name = ?', ['e2eq-get'])];

// Pager: seven more queries, three rows per page.
for ($i = 1; $i <= 7; $i++) {
	wmi_e2e_add_query(WMI_E2E_ID + 100 + $i, 'e2eq-page-' . $i, 'root\\\\CIMV2', 'SELECT 1', 'Name');
}

$total_queries = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM wmi_wql_queries WHERE name LIKE ?', ['e2eq-%']);

$pager = [];

foreach (['p1' => ['filter' => 'e2eq-page', 'rows' => 3, 'page' => 1], 'p3' => ['filter' => 'e2eq-page', 'rows' => 3, 'page' => 3], 'one' => ['filter' => 'page-4', 'rows' => 3, 'page' => 1], 'none' => ['filter' => 'no-such-query', 'rows' => 3, 'page' => 1]] as $label => $q) {
	$list = $qlist($q);
	$text = preg_replace('/\s+/', ' ', strip_tags($list['body']));

	$pager[$label] = ['nav' => preg_match('/of (\d+)/', $text, $m) ? (int) $m[1] : -1, 'rows_shown' => substr_count($list['body'], "id='line"), 'rows_shown_alt' => preg_match_all('/e2eq-page-\d/', $list['body']) ?: 0];
}

$out['query_pager'] = $pager + ['total_matching' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM wmi_wql_queries WHERE name LIKE ?', ['e2eq-page-%']), 'total_all' => $total_queries];

// Bulk delete of two queries.
$ids  = array_column(db_fetch_assoc_prepared('SELECT id FROM wmi_wql_queries WHERE name IN (?, ?)', ['e2eq-page-6', 'e2eq-page-7']), 'id');
$page = $admin->page($pages['queries']);
$conf = $admin->post($pages['queries'], ['action' => 'actions', 'drp_action' => 1, 'chk_' . $ids[0] => 'on', 'chk_' . $ids[1] => 'on'], $page['token']);
$del  = $admin->post($pages['queries'], ['action' => 'actions', 'drp_action' => 1, 'selected_items' => serialize(array_map('strval', $ids))], $page['token']);

$out['query_delete'] = ['confirm_lists' => strpos($conf['body'], 'e2eq-page-6') !== false, 'left' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM wmi_wql_queries WHERE name IN (?, ?)', ['e2eq-page-6', 'e2eq-page-7'])];

// The query tool.
$secret = 'T00l-secret-' . bin2hex(random_bytes(4));
$result = wmi_e2e_result('Win32_Service', ['Name', 'State'], [['Spooler', 'Running'], ['<b>Evil</b>', '<script>x</script>']]);

wmi_e2e_wmic_default($result, 0);

$tool = function (array $fields, bool $csrf = true) use ($admin, $pages): array {
	$page = $admin->page($pages['tools']);

	return $admin->post($pages['tools'], $fields, $csrf ? $page['token'] : '', ['action' => 'query', 'header' => 'false']);
};

$tool_fields = ['host' => 'Win-Host.invalid', 'username' => 'DOM\\tool', 'password' => $secret, 'namespace' => '', 'command' => 'SELECT Name,State FROM Win32_Service'];

$first  = $tool($tool_fields);
$second = $tool($tool_fields);
$page   = $admin->request($pages['tools']);
$again  = $admin->request($pages['tools']);

$save_path = session_save_path() ?: sys_get_temp_dir();
$in_session = false;

foreach (glob(rtrim($save_path, '/') . '/sess_*') ?: [] as $file) {
	if (is_readable($file) && strpos((string) file_get_contents($file), $secret) !== false) {
		$in_session = true;
	}
}

$out['tool_run'] = [
	'code'            => $first['code'],
	'shows_rows'      => strpos($first['body'], 'Spooler') !== false,
	'escapes_markup'  => strpos($first['body'], '<script>x</script>') === false && strpos($first['body'], '&lt;script&gt;') !== false,
	'secret_in_reply' => strpos($first['body'], $secret) !== false || strpos($second['body'], $secret) !== false,
	'secret_in_page'  => strpos($page['body'], $secret) !== false || strpos($again['body'], $secret) !== false,
	'secret_in_session' => $in_session,
	'username_in_page' => strpos($page['body'], 'DOM') !== false,
	'password_field_empty' => preg_match("/id='password' value=''/", $page['body']) === 1,
	'argv'            => wmi_e2e_last_argv(),
];

$bad = [];

foreach (['no_password' => ['password' => ''], 'no_host' => ['host' => ''], 'no_user' => ['username' => '']] as $label => $change) {
	$r = $tool($change + $tool_fields);

	$bad[$label] = strpos($r['body'], 'ERROR:') !== false;
}

$out['tool_missing_fields'] = $bad;

wmi_e2e_wmic_default(['ERROR: NTSTATUS: access <denied>'], 1);
$r = $tool($tool_fields);

$out['tool_failure'] = ['has_error' => strpos($r['body'], 'ERROR:') !== false, 'escaped' => strpos($r['body'], '<denied>') === false && strpos($r['body'], '&lt;denied&gt;') !== false];

wmi_e2e_wmic_default($result, 0);

$namespaces = [];

foreach (['empty' => '', 'single' => 'root\\CIMV2', 'double' => 'root\\\\CIMV2'] as $label => $namespace) {
	$tool(['namespace' => $namespace] + $tool_fields);

	$namespaces[$label] = array_values(array_filter(wmi_e2e_last_argv(), fn ($a) => strpos($a, '--namespace=') === 0));
}

$out['tool_namespaces'] = $namespaces;

$r = $tool($tool_fields, false);
$out['tool_no_csrf'] = ['code' => $r['code'], 'ran' => strpos($r['body'], 'Spooler') !== false];

$r = $nowmi->post($pages['tools'], $tool_fields, $nowmi->page($pages['tools'])['token'], ['action' => 'query', 'header' => 'false']);
$out['tool_no_realm'] = ['ran' => strpos($r['body'], 'Spooler') !== false];

$r = $admin->request($pages['tools'], ['action' => 'query', 'header' => 'false'] + $tool_fields);
$out['tool_get'] = ['code' => $r['code'], 'ran' => strpos($r['body'], 'Spooler') !== false];

// The Device Template hook: the confirmation form posts back to the edit page.
$template = WMI_E2E_ID + 7;

db_execute_prepared("INSERT INTO host_template (id, hash, name) VALUES (?, 'e2ehash', 'wmi-e2e-template')", [$template]);

$hooks = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_hooks WHERE name = 'wmi' AND hook = 'device_template_top' AND status = 1");

$out['hook_registered'] = $hooks > 0;

if ($hooks > 0) {
	$query = (int) db_fetch_cell_prepared('SELECT id FROM wmi_wql_queries WHERE name = ?', ['e2eq-new']);

	$conf = $admin->request('host_templates.php', ['action' => 'item_remove_wq_confirm', 'id' => $query, 'host_template_id' => $template]);

	$out['hook_confirm'] = ['code' => $conf['code'], 'action' => preg_match('/<form[^>]*?\\baction=[\'"]([^\'"]+)/', $conf['body'], $m) ? $m[1] : '', 'has_name' => strpos($conf['body'], 'e2eq-new') !== false];

	$page = $admin->page('host_templates.php', ['action' => 'edit', 'id' => $template]);

	$admin->post('host_templates.php', ['host_template_id' => $template, 'wmi_query_id' => $query], $page['token'], ['action' => 'item_add_wq']);
	$out['hook_linked'] = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template_wmi_query WHERE host_template_id = ? AND wmi_query_id = ?', [$template, $query]);

	$edit = $admin->request('host_templates.php', ['action' => 'edit', 'id' => $template]);
	$out['hook_edit_lists'] = strpos($edit['body'], 'e2eq-new') !== false;

	$admin->post('host_templates.php', ['host_template_id' => $template, 'id' => $query], $page['token'], ['action' => 'item_remove_wq']);
	$out['hook_unlinked'] = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template_wmi_query WHERE host_template_id = ?', [$template]);
}

$out['log'] = wmi_e2e_log_since_boot();

print "\n" . json_encode($out) . "\n";
