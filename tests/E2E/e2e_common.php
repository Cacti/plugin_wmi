<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Shared helpers for the end-to-end drivers (collector_flow.php and
 * pages_flow.php). tests/ ships inside the plugin directory, so this file
 * does nothing when a web server runs it: it needs the CLI, WMI_E2E=1 and a
 * work directory that the test process names and creates. It takes no path,
 * command or file name from a request and writes only inside that directory
 * (plus the Cacti database and log the driver is meant to exercise).
 *
 * Every database row a driver creates uses ids from 900001 upward and is
 * removed again, and cacti.log is cut back to its starting length.
 */

if (PHP_SAPI !== 'cli' || getenv('WMI_E2E') !== '1' || getenv('WMI_E2E_WORKDIR') === false || !is_dir((string) getenv('WMI_E2E_WORKDIR'))) {
	http_response_code(404);

	exit;
}

const WMI_E2E_ID  = 900001;
const WMI_E2E_SEP = '|+|';

$GLOBALS['wmi_e2e'] = [
	'root'  => dirname(__DIR__, 4),
	'work'  => rtrim((string) getenv('WMI_E2E_WORKDIR'), '/'),
	'saved' => [],
	'log'   => ['file' => '', 'exists' => false, 'size' => 0],
];

// Cacti's configuration variables must land in the global scope, so the
// libraries are loaded here at file scope, not inside a function.
chdir($GLOBALS['wmi_e2e']['root']);

$_SERVER['argv'] = ['wmi_e2e'];

require_once $GLOBALS['wmi_e2e']['root'] . '/include/cli_check.php';
require_once $GLOBALS['wmi_e2e']['root'] . '/plugins/wmi/includes/database.php';
require_once $GLOBALS['wmi_e2e']['root'] . '/plugins/wmi/includes/functions.php';

/**
 * Remember what the run must restore, register that restoration for the end
 * of the process and reset the fixtures.
 */
function wmi_e2e_boot(): void {
	$root = $GLOBALS['wmi_e2e']['root'];

	$log_file = read_config_option('path_cactilog') ?: $root . '/log/cacti.log';

	$GLOBALS['wmi_e2e']['log'] = [
		'file'   => $log_file,
		'exists' => is_file($log_file),
		'size'   => is_file($log_file) ? (int) filesize($log_file) : 0,
	];

	register_shutdown_function('wmi_e2e_finish');

	plugin_wmi_setup_tables();

	foreach (['path_wmi', 'log_verbosity', 'wmi_processes', 'path_php_binary'] as $name) {
		$GLOBALS['wmi_e2e']['saved'][$name] = db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', [$name]) ?: false;
	}

	$GLOBALS['wmi_e2e']['saved']['plugin_row_added'] = false;

	if ((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_config WHERE directory = ?', ['wmi']) === 0) {
		db_execute("INSERT INTO plugin_config (directory, name, status, author, webpage, version) VALUES ('wmi', 'wmi', 1, '', '', '1')");

		$GLOBALS['wmi_e2e']['saved']['plugin_row_added'] = true;
	}

	wmi_e2e_purge();

	set_config_option('log_verbosity', (string) POLLER_VERBOSITY_HIGH);

	wmi_e2e_write_wmic();
}

/**
 * Remove every row this run may have created.
 */
function wmi_e2e_purge(): void {
	$base = WMI_E2E_ID;

	db_execute_prepared('DELETE FROM host_wmi_cache WHERE host_id >= ?', [$base]);
	db_execute_prepared('DELETE FROM host_wmi_query WHERE host_id >= ?', [$base]);
	db_execute_prepared('DELETE FROM host_template_wmi_query WHERE host_template_id >= ?', [$base]);
	db_execute_prepared('DELETE FROM host_template_wmi_query WHERE wmi_query_id IN (SELECT id FROM wmi_wql_queries WHERE name LIKE ?)', ['e2e%']);
	db_execute_prepared('DELETE FROM wmi_wql_queries WHERE id >= ? OR name LIKE ?', [$base, 'e2e%']);
	db_execute_prepared('DELETE FROM wmi_user_accounts WHERE id >= ? OR name LIKE ?', [$base, 'e2e%']);
	db_execute_prepared('DELETE FROM host WHERE id >= ?', [$base]);
	db_execute_prepared('DELETE FROM host_template WHERE id >= ?', [$base]);
	db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id >= ?', [$base]);
	db_execute_prepared('DELETE FROM user_auth WHERE id >= ?', [$base]);
	db_execute('DELETE FROM wmi_processes');
}

/**
 * Shutdown handler: purge rows, restore settings, the plugin row and the log.
 */
function wmi_e2e_finish(): void {
	$state = $GLOBALS['wmi_e2e'];

	wmi_e2e_purge();

	foreach (['path_wmi', 'log_verbosity', 'wmi_processes', 'path_php_binary'] as $name) {
		if ($state['saved'][$name] !== false) {
			db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', [$name, $state['saved'][$name]]);
		} else {
			db_execute_prepared('DELETE FROM settings WHERE name = ?', [$name]);
		}
	}

	if ($state['saved']['plugin_row_added']) {
		db_execute_prepared('DELETE FROM plugin_config WHERE directory = ?', ['wmi']);
	}

	foreach (['wmic', 'out', 'status', 'argv', 'calls', 'rules', 'auth_content', 'auth_path', 'auth_mode'] as $name) {
		$path = $state['work'] . '/' . $name;

		if (is_file($path)) {
			unlink($path);
		}
	}

	foreach (glob($state['work'] . '/out.*') ?: [] as $path) {
		unlink($path);
	}

	$log = $state['log'];

	clearstatcache(true, $log['file']);

	if (!is_file($log['file'])) {
		return;
	}

	if (!$log['exists']) {
		unlink($log['file']);
	} elseif (filesize($log['file']) > $log['size']) {
		$handle = fopen($log['file'], 'r+');

		if ($handle !== false) {
			ftruncate($handle, $log['size']);
			fclose($handle);
		}
	}
}

/**
 * Log lines written since boot.
 */
function wmi_e2e_log_since_boot(): string {
	$log = $GLOBALS['wmi_e2e']['log'];

	clearstatcache(true, $log['file']);

	return is_file($log['file']) ? (string) substr((string) file_get_contents($log['file']), $log['size']) : '';
}

/**
 * Write the fake wmic executable. It prints the canned output chosen by the
 * first matching rule in the rules file (tab separated: substring of the
 * argument list, exit status, output file name) or the default output, and
 * records its arguments and every call.
 */
function wmi_e2e_write_wmic(): void {
	$work = $GLOBALS['wmi_e2e']['work'];
	$path = $work . '/wmic';

	file_put_contents($path, <<<'SH'
#!/bin/sh
W="$(dirname "$0")"
ALL="$*"
printf '%s\n' "$@" > "$W/argv"
printf '%s\n' "$ALL" >> "$W/calls"
for a in "$@"; do
  case "$a" in
    --authentication-file=*)
      AUTH="${a#--authentication-file=}"
      cp "$AUTH" "$W/auth_content"
      printf '%s' "$AUTH" > "$W/auth_path"
      stat -c '%a' "$AUTH" > "$W/auth_mode" 2>/dev/null || stat -f '%Lp' "$AUTH" > "$W/auth_mode"
      ;;
  esac
done
echo "wmic: note on stderr" >&2
OUT="$W/out"
ST="$(cat "$W/status")"
TAB="$(printf '\t')"
if [ -f "$W/rules" ]; then
  while IFS="$TAB" read -r PAT RST RFILE; do
    case "$ALL" in
      *"$PAT"*) OUT="$W/$RFILE"; ST="$RST"; break ;;
    esac
  done < "$W/rules"
fi
cat "$OUT"
exit "$ST"
SH
	);
	chmod($path, 0700);

	wmi_e2e_wmic_default([], 0);
	set_config_option('path_wmi', $path);
}

/**
 * Canned wmic output used when no rule matches.
 *
 * @param array<int, string> $lines
 */
function wmi_e2e_wmic_default(array $lines, int $status): void {
	$work = $GLOBALS['wmi_e2e']['work'];

	file_put_contents($work . '/out', $lines === [] ? '' : implode("\n", $lines) . "\n");
	file_put_contents($work . '/status', (string) $status);

	foreach (['rules', 'calls'] as $name) {
		if (is_file($work . '/' . $name)) {
			unlink($work . '/' . $name);
		}
	}
}

/**
 * Canned output for calls whose argument list contains a substring (a host
 * name or a part of the query).
 *
 * @param array<int, array{0: string, 1: int, 2: array<int, string>}> $rules pattern, status, stdout lines
 */
function wmi_e2e_wmic_rules(array $rules): void {
	$work = $GLOBALS['wmi_e2e']['work'];
	$text = '';

	foreach ($rules as $n => [$pattern, $status, $lines]) {
		file_put_contents($work . '/out.' . $n, $lines === [] ? '' : implode("\n", $lines) . "\n");

		$text .= $pattern . "\t" . $status . "\tout." . $n . "\n";
	}

	file_put_contents($work . '/rules', $text);
}

/**
 * Result lines in the shape wmic prints: class, header, then one row each.
 *
 * @param array<int, string>             $header
 * @param array<int, array<int, string>> $rows
 *
 * @return array<int, string>
 */
function wmi_e2e_result(string $class, array $header, array $rows): array {
	$lines = [$class, implode(WMI_E2E_SEP, $header)];

	foreach ($rows as $row) {
		$lines[] = implode(WMI_E2E_SEP, $row);
	}

	return $lines;
}

/**
 * Arguments of the last wmic call.
 *
 * @return array<int, string>
 */
function wmi_e2e_last_argv(): array {
	$path = $GLOBALS['wmi_e2e']['work'] . '/argv';

	return is_file($path) ? array_values(array_filter(explode("\n", (string) file_get_contents($path)), 'strlen')) : [];
}

/**
 * Every wmic call so far, one argument string per call.
 *
 * @return array<int, string>
 */
function wmi_e2e_calls(): array {
	$path = $GLOBALS['wmi_e2e']['work'] . '/calls';

	return is_file($path) ? array_values(array_filter(explode("\n", (string) file_get_contents($path)), 'strlen')) : [];
}

/**
 * What the last wmic call recorded about its credentials file: the content,
 * its mode, and whether it still exists.
 *
 * @return array{content: string, mode: string, path: string, exists: bool}
 */
function wmi_e2e_last_auth(): array {
	$work = $GLOBALS['wmi_e2e']['work'];
	$read = fn (string $name): string => is_file($work . '/' . $name) ? (string) file_get_contents($work . '/' . $name) : '';
	$path = $read('auth_path');

	return ['content' => $read('auth_content'), 'mode' => trim($read('auth_mode')), 'path' => $path, 'exists' => $path !== '' && file_exists($path)];
}

function wmi_e2e_add_account(int $id, string $name, string $username, string $password): void {
	$wmi = new Linux_WMI();

	db_execute_prepared('INSERT INTO wmi_user_accounts (id, name, username, password) VALUES (?, ?, ?, ?)', [$id, $name, $username, $wmi->encode($password)]);
}

function wmi_e2e_add_host(int $id, int $template, int $account, string $hostname, int $status = 3, string $disabled = ''): void {
	db_execute_prepared('INSERT INTO host (id, description, hostname, host_template_id, status, disabled, wmi_account) VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, 'wmi-e2e-' . $id, $hostname, $template, $status, $disabled, $account]);
}

function wmi_e2e_add_query(int $id, string $name, string $namespace, string $query, string $primary_key, int $frequency = 60, string $enabled = 'on'): void {
	db_execute_prepared("INSERT INTO wmi_wql_queries (id, hash, name, frequency, enabled, namespace, query, primary_key) VALUES (?, '', ?, ?, ?, ?, ?, ?)", [$id, $name, $frequency, $enabled, $namespace, $query, $primary_key]);
}

function wmi_e2e_link_template(int $template, int $query): void {
	db_execute_prepared('INSERT INTO host_template_wmi_query (host_template_id, wmi_query_id) VALUES (?, ?)', [$template, $query]);
}

/**
 * Distinct cache indexes for a host and query, sorted.
 *
 * @return array<int, string>
 */
function wmi_e2e_cached(int $host, int $query): array {
	return array_column(db_fetch_assoc_prepared(
		"SELECT DISTINCT wmi_index FROM host_wmi_cache WHERE host_id = ? AND wmi_query_id = ? AND field_name = 'Name' ORDER BY wmi_index",
		[$host, $query]
	), 'wmi_index');
}

function wmi_e2e_cache_count(int $host, int $query): int {
	return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_wmi_cache WHERE host_id = ? AND wmi_query_id = ?', [$host, $query]);
}

/**
 * Run a callable with its stdout captured and discarded.
 *
 * @return mixed What the callable returned.
 */
function wmi_e2e_quiet(callable $call) {
	ob_start();

	try {
		return $call();
	} finally {
		ob_end_clean();
	}
}

/**
 * Create a user cloned from admin with the given realms.
 *
 * @param array<int, int> $realms
 */
function wmi_e2e_add_user(int $id, string $username, string $password, array $realms): void {
	$row = db_fetch_row_prepared('SELECT * FROM user_auth WHERE id = 1');

	$row['id']       = $id;
	$row['username'] = $username;
	$row['password'] = password_hash($password, PASSWORD_DEFAULT);

	// Columns differ between Cacti releases; only reset the ones this one has.
	foreach (['must_change_password' => '', 'password_change' => '', 'enabled' => 'on', 'locked' => '', 'failed_attempts' => 0, 'tfa_enabled' => ''] as $column => $value) {
		if (array_key_exists($column, $row)) {
			$row[$column] = $value;
		}
	}

	sql_save($row, 'user_auth', 'id', false);

	foreach ($realms as $realm) {
		db_execute_prepared('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (?, ?)', [$id, $realm]);
	}
}

/**
 * A small HTTP client with a cookie jar for the page flows.
 */
class Wmi_E2e_Http {
	private string $jar;

	public function __construct(private string $base) {
		$this->jar = tempnam($GLOBALS['wmi_e2e']['work'], 'jar');
	}

	/**
	 * @param array<string, string|int> $query
	 * @param array<string, mixed>|null $post
	 *
	 * @return array{code: int, body: string, location: string}
	 */
	public function request(string $path, array $query = [], ?array $post = null): array {
		$url = $this->base . $path . ($query === [] ? '' : '?' . http_build_query($query));
		$ch  = curl_init($url);

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_COOKIEJAR      => $this->jar,
			CURLOPT_COOKIEFILE     => $this->jar,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_HEADER         => true,
			CURLOPT_USERAGENT      => 'wmi-e2e',
		]);

		if ($post !== null) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
		}

		$response = (string) curl_exec($ch);
		$info     = curl_getinfo($ch);

		curl_close($ch);

		$headers = substr($response, 0, (int) $info['header_size']);

		return [
			'code'     => (int) $info['http_code'],
			'body'     => substr($response, (int) $info['header_size']),
			'location' => preg_match('/^Location:\s*(\S+)/mi', $headers, $m) ? $m[1] : '',
		];
	}

	/**
	 * The CSRF token embedded in a page, or '' when there is none.
	 */
	public static function token(string $html): string {
		if (preg_match('/name=.__csrf_magic. value="([^"]+)"/', $html, $m) || preg_match("/csrfMagicToken='([^']+)'/", $html, $m)) {
			return $m[1];
		}

		return '';
	}

	public function login(string $username, string $password): bool {
		$page = $this->request('index.php');
		$done = $this->request('index.php', [], [
			'__csrf_magic'   => self::token($page['body']),
			'action'         => 'login',
			'login_username' => $username,
			'login_password' => $password,
		]);

		return $done['code'] === 302;
	}

	/**
	 * GET a page and return it together with a CSRF token valid for the
	 * session, so a following POST can be sent the way a browser sends it.
	 *
	 * @param array<string, string|int> $query
	 *
	 * @return array{code: int, body: string, location: string, token: string}
	 */
	public function page(string $path, array $query = []): array {
		$page = $this->request($path, $query);

		$page['token'] = self::token($page['body']);

		return $page;
	}

	/**
	 * POST with the session's CSRF token, or without one.
	 *
	 * @param array<string, mixed> $fields
	 * @param array<string, string|int> $query
	 *
	 * @return array{code: int, body: string, location: string}
	 */
	public function post(string $path, array $fields, string $token, array $query = []): array {
		if ($token !== '') {
			$fields['__csrf_magic'] = $token;
		}

		return $this->request($path, $query, $fields);
	}
}

/**
 * Start PHP's built-in server for the Cacti tree under the URL prefix
 * /cacti/, as the default url_path expects, and wait until it answers.
 *
 * @return array{process: resource, base: string}
 */
function wmi_e2e_start_server(): array {
	$state = $GLOBALS['wmi_e2e'];
	$www   = $state['work'] . '/www';

	if (!is_dir($www)) {
		mkdir($www, 0700);
	}

	if (!is_link($www . '/cacti')) {
		symlink($state['root'], $www . '/cacti');
	}

	$probe = stream_socket_server('tcp://127.0.0.1:0');
	$name  = stream_socket_get_name($probe, false);
	$port  = (int) substr($name, strrpos($name, ':') + 1);

	fclose($probe);

	$process = proc_open(
		[PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $www],
		[0 => ['file', '/dev/null', 'r'], 1 => ['file', $state['work'] . '/server.log', 'a'], 2 => ['file', $state['work'] . '/server.log', 'a']],
		$pipes,
		$state['root']
	);

	// Probe with curl: a failed connect there raises no PHP warning, which
	// Cacti would write to its log.
	for ($i = 0; $i < 100; $i++) {
		$probe = curl_init('http://127.0.0.1:' . $port . '/');

		curl_setopt_array($probe, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 2, CURLOPT_RETURNTRANSFER => true]);
		curl_exec($probe);

		$reached = curl_getinfo($probe, CURLINFO_HTTP_CODE) > 0;

		curl_close($probe);

		if ($reached) {
			break;
		}

		usleep(100000);
	}

	return ['process' => $process, 'base' => 'http://127.0.0.1:' . $port . '/cacti/'];
}

/**
 * @param array{process: resource, base: string} $server
 */
function wmi_e2e_stop_server(array $server): void {
	proc_terminate($server['process']);
	proc_close($server['process']);

	$work = $GLOBALS['wmi_e2e']['work'];

	if (is_link($work . '/www/cacti')) {
		unlink($work . '/www/cacti');
	}

	if (is_dir($work . '/www')) {
		rmdir($work . '/www');
	}

	if (is_file($work . '/server.log')) {
		unlink($work . '/server.log');
	}
}
