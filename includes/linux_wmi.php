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

/*
 * $wmi = new Linux_WMI();
 * $wmi->hostname    = '192.168.126.1';
 * $wmi->username    = 'test';
 * $wmi->password    = 'test';
 * $wmi->querynspace = 'root\\CIMV2';
 * $wmi->command     = 'SELECT * FROM Win32_Process';
 *
 * print_r($wmi->fetch());
 */

/**
 * The parameters a transport needs to run one WMI query.
 */
class Wmi_Request {
	public function __construct(
		public string $hostname,
		public string $username,
		public string $password,
		public string $namespace,
		public string $query,
		public string $separator,
		public string $binary
	) {
	}
}

/**
 * A WMI transport runs one query against a host and returns the raw stdout
 * lines: the class name, the delimiter-joined column header, then one
 * delimiter-joined line per row. Backends (the wmic DCOM client, a future
 * PowerShell/CIM path) implement this contract so Linux_WMI stays agnostic.
 */
interface Wmi_Transport {
	/**
	 * @return array<int, string>|false Raw stdout lines, or false on failure.
	 */
	public function query(Wmi_Request $request): array|false;

	/** Last error message, or null after a successful query. */
	public function error(): ?string;
}

/**
 * Default transport: the Linux `wmic` client.
 *
 * The credentials go to wmic in a private authentication file (-A) so they
 * never appear in the process list. stdout is the result; stderr is kept
 * apart and only added to the error text.
 */
class Wmic_Shell_Transport implements Wmi_Transport {
	private ?string $error = null;

	/**
	 * @param int         $timeout  Seconds to wait for wmic before it is stopped.
	 * @param string|null $temp_dir Directory for the authentication file; the
	 *                              system temporary directory when null.
	 */
	public function __construct(
		private int $timeout = 120,
		private ?string $temp_dir = null
	) {
	}

	public function query(Wmi_Request $request): array|false {
		$this->error = null;

		if (preg_match('/[\r\n\0]/', $request->username . $request->password)) {
			$this->error = 'ERROR: WMI credentials must not contain line breaks';

			return false;
		}

		$auth = $this->create_auth_file($request);

		try {
			$result = $this->run(
				(DIRECTORY_SEPARATOR === '/' ? 'exec ' : '') . $this->build_command($request, $auth['file'] ?? null)
			);
		} finally {
			if ($auth !== null) {
				@unlink($auth['file']);
				@rmdir($auth['dir']);
			}
		}

		if ($result === null) {
			$this->error = 'ERROR: Unable to start ' . $request->binary;

			return false;
		}

		if ($result['timed_out']) {
			$this->error = $this->with_stderr('ERROR: wmic did not finish within ' . $this->timeout . ' seconds', $result['stderr']);

			return false;
		}

		if ($result['status'] !== 0) {
			$this->error = $this->with_stderr('ERROR: ' . implode("\n", $result['lines']), $result['stderr']);

			return false;
		}

		if (count($result['lines']) === 0) {
			$this->error = $this->with_stderr('ERROR: WMI Returned no Data', $result['stderr']);

			return false;
		}

		return $result['lines'];
	}

	public function error(): ?string {
		return $this->error;
	}

	/**
	 * Build the wmic command line. Every device-influenced value is shell
	 * escaped; the hostname and namespace are additionally stripped of cmd.exe
	 * metacharacters on win32. The delimiter is quoted so its pipe characters
	 * are not read as a shell pipeline. The hostname follows a literal `//`, so
	 * it can never be read as an option.
	 *
	 * @param string|null $auth_file Credentials file for --authentication-file;
	 *                               without it the credentials are passed as
	 *                               arguments, which any local user can read.
	 */
	public function build_command(Wmi_Request $request, ?string $auth_file = null): string {
		$namespace = $request->namespace !== ''
			? ' --namespace=' . cacti_escapeshellarg($this->single_backslashes($this->strip_metachars($request->namespace)))
			: '';

		$credentials = $auth_file !== null
			? ' --authentication-file=' . cacti_escapeshellarg($auth_file)
			: ' --user=' . cacti_escapeshellarg($request->username) .
				' --password=' . cacti_escapeshellarg($request->password);

		return cacti_escapeshellarg($request->binary) .
			' --delimiter=' . cacti_escapeshellarg($request->separator) .
			$credentials .
			$namespace .
			' //' . cacti_escapeshellarg($this->strip_metachars(trim($request->hostname))) .
			' ' . cacti_escapeshellarg($request->query);
	}

	/**
	 * Namespaces are stored with doubled backslashes between segments, a form
	 * that only collapsed to one backslash inside a double-quoted shell
	 * string. The value is now single-quoted, so collapse it here.
	 */
	private function single_backslashes(string $value): string {
		return preg_replace('/\\\\+/', '\\\\', $value);
	}

	/**
	 * Write the credentials to a file only this user can read, inside a
	 * directory with a random name. Returns null, after logging a warning,
	 * when that is not possible; the caller then falls back to arguments.
	 *
	 * @return array{dir: string, file: string}|null
	 */
	private function create_auth_file(Wmi_Request $request): ?array {
		$base = $this->temp_dir ?? sys_get_temp_dir();
		$dir  = rtrim($base, '/') . '/wmi-' . bin2hex(random_bytes(8));

		if (!is_dir($base) || !is_writable($base) || !@mkdir($dir, 0700)) {
			cacti_log('WARNING: Unable to create a private directory for the wmic credentials; passing them as arguments', false, 'WMI');

			return null;
		}

		$file    = $dir . '/' . bin2hex(random_bytes(8));
		$handle  = $this->open_new_file($file);
		$written = false;

		if ($handle !== false) {
			$content = $this->auth_file_content($request);
			$written = fwrite($handle, $content) === strlen($content);

			fclose($handle);
		}

		if (!$written || !@chmod($file, 0600)) {
			if (is_file($file)) {
				unlink($file);
			}

			rmdir($dir);

			cacti_log('WARNING: Unable to write the wmic credentials file; passing the credentials as arguments', false, 'WMI');

			return null;
		}

		return ['dir' => $dir, 'file' => $file];
	}

	/**
	 * Create a file that must not exist yet, readable only by this user.
	 *
	 * @return resource|false
	 */
	protected function open_new_file(string $file) {
		$old    = umask(0077);
		$handle = @fopen($file, 'x');

		umask($old);

		return $handle;
	}

	/**
	 * Start a process with its stdout and stderr as pipes.
	 *
	 * @param array<int, resource> $pipes
	 *
	 * @return resource|false
	 */
	protected function start_process(string $command, ?array &$pipes) {
		return proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	}

	/**
	 * The samba credentials file format. A DOMAIN\user or DOMAIN/user name is
	 * split the way wmic splits --user.
	 */
	private function auth_file_content(Wmi_Request $request): string {
		$content = '';
		$user    = $request->username;

		if (preg_match('#^([^\\\\/]+)[\\\\/](.+)$#', $user, $match)) {
			$content = 'domain=' . $match[1] . "\n";
			$user    = $match[2];
		}

		return 'username=' . $user . "\n" . 'password=' . $request->password . "\n" . $content;
	}

	/**
	 * Run a command with stdout and stderr read separately, stopping it when
	 * it takes longer than the timeout.
	 *
	 * @return array{status: int, lines: array<int, string>, stderr: string, timed_out: bool}|null
	 */
	private function run(string $command): ?array {
		$process = $this->start_process($command, $pipes);

		if (!is_resource($process)) {
			return null;
		}

		$open     = [1 => $pipes[1], 2 => $pipes[2]];
		$buffer   = [1 => '', 2 => ''];
		$deadline = microtime(true) + $this->timeout;
		$expired  = false;

		foreach ($open as $stream) {
			stream_set_blocking($stream, false);
		}

		while ($open !== []) {
			$left = $deadline - microtime(true);

			if ($left <= 0) {
				$expired = true;

				break;
			}

			$read   = $open;
			$write  = null;
			$except = null;

			@stream_select($read, $write, $except, 0, (int) min(200000, $left * 1000000));

			foreach ($read as $key => $stream) {
				$chunk = fread($stream, 8192);

				if ($chunk === false || ($chunk === '' && feof($stream))) {
					unset($open[$key]);
				} else {
					$buffer[$key] .= $chunk;
				}
			}
		}

		if ($expired) {
			proc_terminate($process, 9);
		}

		fclose($pipes[1]);
		fclose($pipes[2]);

		$status = proc_close($process);

		if (substr($buffer[1], -1) === "\n") {
			$buffer[1] = substr($buffer[1], 0, -1);
		}

		return [
			'status'    => $expired ? -1 : $status,
			'lines'     => $buffer[1] === '' ? [] : array_map('rtrim', explode("\n", $buffer[1])),
			'stderr'    => substr(trim($buffer[2]), 0, 2000),
			'timed_out' => $expired,
		];
	}

	private function with_stderr(string $message, string $stderr): string {
		return $stderr === '' ? $message : ($message === 'ERROR: ' ? 'ERROR: ' : $message . "\n") . $stderr;
	}

	/**
	 * A hostname or namespace never legitimately contains shell or cmd.exe
	 * metacharacters. cmd.exe ignores \" , toggles quoting on every " , and
	 * expands %VAR% despite quoting, so strip those on win32 before quoting.
	 */
	private function strip_metachars(string $value): string {
		global $config;

		if (isset($config['cacti_server_os']) && $config['cacti_server_os'] === 'win32') {
			$value = str_replace(['"', '&', '|', '^', '<', '>', '(', ')', '%'], '', $value);
		}

		return $value;
	}
}

/**
 * PowerShell/CIM transport. Runs the query with Get-CimInstance through pwsh or
 * powershell.exe: usable on a Windows Cacti server and, for a remote target,
 * over WinRM/CIM against modern Windows hosts the legacy wmic client can no
 * longer authenticate to.
 *
 * Credentials and every device-supplied value pass through the child process
 * environment and a fixed script on stdin, never on the command line, so
 * neither a shell nor the process table (ps) ever sees the password, and no
 * value is interpolated into the script (no PowerShell injection).
 */
class PowerShellCim_Transport implements Wmi_Transport {
	/**
	 * The PowerShell program. Reads every value from the environment and emits
	 * the class name, the separator-joined column header, then one
	 * separator-joined row per instance: the shape the wmic transport produces.
	 */
	private const SCRIPT = <<<'PS'
		$ErrorActionPreference = 'Stop'
		$sep = $env:WMI_SEP
		$params = @{ Query = $env:WMI_QUERY; ErrorAction = 'Stop' }
		if (![string]::IsNullOrEmpty($env:WMI_NS)) {
		    $params['Namespace'] = ($env:WMI_NS -replace '\\+', '/')
		}
		$session = $null
		if (![string]::IsNullOrEmpty($env:WMI_HOST)) {
		    $secure = ConvertTo-SecureString $env:WMI_PASS -AsPlainText -Force
		    $cred = New-Object System.Management.Automation.PSCredential($env:WMI_USER, $secure)
		    $session = New-CimSession -ComputerName $env:WMI_HOST -Credential $cred
		    $params['CimSession'] = $session
		}
		try {
		    $items = @(Get-CimInstance @params)
		} finally {
		    if ($session) { Remove-CimSession -CimSession $session }
		}
		if ($items.Count -eq 0) { exit 0 }
		$props = $items[0].CimInstanceProperties.Name
		Write-Output $items[0].CimClass.CimClassName
		Write-Output ($props -join $sep)
		foreach ($item in $items) {
		    $vals = foreach ($p in $props) { [string]$item.$p }
		    Write-Output ($vals -join $sep)
		}
		PS;

	private ?string $error = null;

	/** @var callable(string, array<int, string>, string, array<string, string>): array{exit: int, stdout: array<int, string>, stderr: string} */
	private $runner;

	/**
	 * @param string        $binary The PowerShell binary (pwsh, or powershell.exe on win32).
	 * @param callable|null $runner Process runner seam; the default uses proc_open.
	 */
	public function __construct(
		public string $binary = 'pwsh',
		?callable $runner = null
	) {
		$this->runner = $runner ?? $this->default_runner();
	}

	public function query(Wmi_Request $request): array|false {
		$this->error = null;

		$env = [
			'WMI_HOST'  => trim($request->hostname),
			'WMI_USER'  => $request->username,
			'WMI_PASS'  => $request->password,
			'WMI_NS'    => $request->namespace,
			'WMI_QUERY' => $request->query,
			'WMI_SEP'   => $request->separator,
		];

		$result = ($this->runner)(
			$this->binary,
			['-NoProfile', '-NonInteractive', '-Command', '-'],
			self::SCRIPT,
			$env
		);

		if ($result['exit'] !== 0) {
			$detail      = $result['stderr'] !== '' ? $result['stderr'] : implode("\n", $result['stdout']);
			$this->error = __('ERROR:', 'wmi') . ' ' . trim($detail);

			return false;
		}

		if (count($result['stdout']) === 0) {
			$this->error = __('ERROR: WMI Returned no Data', 'wmi');

			return false;
		}

		return $result['stdout'];
	}

	public function error(): ?string {
		return $this->error;
	}

	private function default_runner(): callable {
		/**
		 * @param array<int, string>    $args
		 * @param array<string, string> $env
		 *
		 * @return array{exit: int, stdout: array<int, string>, stderr: string}
		 */
		return function (string $binary, array $args, string $stdin, array $env): array {
			$descriptors = [
				0 => ['pipe', 'r'],
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			];

			// proc_open replaces the environment wholesale, so merge our values
			// onto the inherited one to keep PATH and friends.
			$child_env = array_merge(getenv(), $env);

			$process = proc_open(array_values(array_merge([$binary], $args)), $descriptors, $pipes, null, $child_env);

			if (!is_resource($process)) {
				return ['exit' => -1, 'stdout' => [], 'stderr' => 'proc_open failed'];
			}

			fwrite($pipes[0], $stdin);
			fclose($pipes[0]);

			$stdout = stream_get_contents($pipes[1]);
			$stderr = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);

			$exit = proc_close($process);

			$stdout = str_replace("\r\n", "\n", rtrim($stdout, "\r\n"));

			return [
				'exit'   => $exit,
				'stdout' => $stdout === '' ? [] : explode("\n", $stdout),
				'stderr' => $stderr,
			];
		};
	}
}

class Linux_WMI {
	public int|string $hostid  = '';   // Host id, to pull authentication from
	public string $hostname    = '';   // Hostname / IP to contact
	public string $username    = '';   // Username to authenticate with
	public string $password    = '';   // Password to authenticate with
	public string $command     = '';   // The WMI query to run
	public string $binary      = '/usr/bin/wmic';
	public string $separator   = '|+|';
	public bool|string $error  = false; // false, or the last error message
	public string $indexkey    = '';   // Key to use as the index
	public string $keys        = '';   // Comma separated keys
	public string $queryclass  = '';   // Class name to pull
	public string $querynspace = '';  // Namespace to pull

	/** @var array<int, array<int, string>> Parsed rows from the last fetch(). */
	public array $results = [];

	private Wmi_Transport $transport;

	public function __construct(int|string $hostid = '', ?Wmi_Transport $transport = null) {
		$this->transport = $transport ?? self::default_transport();

		if ($hostid !== '') {
			$this->hostid = $hostid;

			// Ensure we have a username / password pair setup for this host
			$this->retrieve_account();
		}
	}

	/**
	 * Pick the transport for the Cacti server it runs on: PowerShell/CIM on a
	 * Windows server (the Linux wmic client is not present there), the wmic
	 * client otherwise. An explicit transport passed to the constructor wins.
	 */
	private static function default_transport(): Wmi_Transport {
		global $config;

		if (isset($config['cacti_server_os']) && $config['cacti_server_os'] === 'win32') {
			return new PowerShellCim_Transport('powershell.exe');
		}

		return new Wmic_Shell_Transport();
	}

	public function create_query(): bool {
		$this->command = 'SELECT ';

		if ($this->keys !== '') {
			if ($this->indexkey !== '') {
				$this->command .= $this->indexkey . ',';
			}

			$this->command .= $this->keys;
		} elseif ($this->indexkey !== '') {
			$this->command .= $this->indexkey . ',';
		} else {
			$this->command = '';
			$this->error   = 'ERROR: WMI Keys and Index is Empty!';

			return false;
		}

		if ($this->queryclass === '') {
			$this->command = '';
			$this->error   = 'ERROR: WMI Query Class is empty!';

			return false;
		}

		$this->command .= ' FROM ' . $this->queryclass;

		return true;
	}

	public function fetch_key_index(string $name): int|false {
		if (!isset($this->results[1])) {
			$this->error = 'ERROR: Empty Result!';

			return false;
		}

		foreach ($this->results[1] as $i => $a) {
			if ($a === $name) {
				return $i;
			}
		}

		$this->error = 'ERROR: Key not found';

		return false;
	}

	public function fetch_value(string $keyname, string $index): string|false {
		$i = $this->fetch_key_index($this->indexkey);
		$k = $this->fetch_key_index($keyname);

		if ($i === false || $k === false) {
			return false;
		}

		foreach ($this->data_rows() as $r) {
			if (str_replace([' ', '(', ')'], '', $r[$i]) === $index) {
				return $r[$k];
			}
		}

		return false;
	}

	public function print_fetch_key_value_pair(string $keyname, string $index): void {
		$i = $this->fetch_key_index($this->indexkey);
		$k = $this->fetch_key_index($keyname);

		if ($i === false || $k === false) {
			return;
		}

		foreach ($this->data_rows() as $r) {
			if (str_replace([' ', '(', ')'], '', $r[$i]) === $index) {
				print "$keyname!" . $r[$k] . "'" . PHP_EOL;
			}
		}
	}

	public function print_indexes(): void {
		$k = $this->fetch_key_index($this->indexkey);

		if ($k === false) {
			return;
		}

		foreach ($this->data_rows() as $r) {
			// Indexes should not have spaces in their name so we remove them
			$name = str_replace([' ', '(', ')'], '', $r[$k]);
			print $name . '!' . $name . PHP_EOL;
		}
	}

	/** @return array<int, string>|false The column header, or false if unset. */
	public function fetch_indexes(): array|false {
		return $this->results[1] ?? false;
	}

	public function fetch_class(): string|false {
		return $this->results[0][0] ?? false;
	}

	/** @return array<int, array<int, string>> */
	public function fetch_data(): array {
		return $this->data_rows();
	}

	/** @return array<int, array<int, string>>|false */
	public function fetch(): array|false {
		if ($this->command === '') {
			$this->error = 'ERROR: WMI Query is empty';

			return false;
		}

		$output = $this->exec();

		if ($output === false) {
			return false;
		}

		$this->results = array_map(
			fn (string $line): array => explode($this->effective_separator(), $line),
			$output
		);

		return $this->results;
	}

	/**
	 * Run the current query through the transport and return its raw lines.
	 *
	 * @return array<int, string>|false
	 */
	public function exec(): array|false {
		if ($this->username === '' || $this->password === '') {
			$this->error = 'ERROR: Username or Password not set!';

			return false;
		}

		$output = $this->transport->query($this->request());

		if ($output === false) {
			$this->error = $this->transport->error() ?? 'ERROR: WMI query failed';

			return false;
		}

		return $output;
	}

	/**
	 * Render the shell command for the wmic transport. Retained for callers and
	 * tests that inspect the command; non-shell transports return false.
	 */
	public function getcommand(): string|false {
		if (!$this->transport instanceof Wmic_Shell_Transport) {
			return false;
		}

		return $this->transport->build_command($this->request());
	}

	public function retrieve_account(): bool {
		if ($this->hostid === '') {
			$this->error = 'ERROR: hostid is not set!';

			return false;
		}

		$info = db_fetch_row_prepared('SELECT pwa.*
			FROM wmi_user_accounts AS pwa
			INNER JOIN host AS h
			WHERE pwa.id = h.wmi_account
			AND h.id = ?',
			[$this->hostid]);

		if (!isset($info['username'])) {
			$this->error = 'ERROR: WMI Authentication account not found!';

			return false;
		}

		$this->username = $info['username'];
		$this->password = $this->decode($info['password']);

		return true;
	}

	public function decode(string $info): string {
		$raw = base64_decode($info, true);

		if ($raw === false) {
			return '';
		}

		// allowed_classes => false forbids object instantiation, so no gadget
		// chain can run; the blob is our own base64(serialize()) from encode().
		// nosemgrep: php.lang.security.unserialize-use.unserialize-use
		$decoded = unserialize($raw, ['allowed_classes' => false]);

		return is_array($decoded) && isset($decoded['password']) ? (string) $decoded['password'] : '';
	}

	public function encode(string $info): string {
		$payload = [
			rand(1, time()) => rand(1, time()),
			'password'      => $info,
			rand(1, time()) => rand(1, time()),
		];

		return base64_encode(serialize($payload));
	}

	private function request(): Wmi_Request {
		return new Wmi_Request(
			$this->hostname,
			$this->username,
			$this->password,
			$this->querynspace,
			$this->command,
			$this->effective_separator(),
			$this->binary
		);
	}

	/**
	 * The separator used for both the transport request and parsing its
	 * output. Falls back to the default when $separator (a public,
	 * mutable property) is empty, since an empty delimiter would make
	 * explode() throw (PHP 8) when parsing the results, and would make the
	 * wmic/PowerShell transport emit unsplit rows.
	 *
	 * @return non-empty-string
	 */
	private function effective_separator(): string {
		return $this->separator !== '' ? $this->separator : '|+|';
	}

	/**
	 * The data rows: results with the class-name and column-header lines
	 * removed.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function data_rows(): array {
		if (count($this->results) <= 2) {
			return [];
		}

		return array_slice($this->results, 2);
	}
}
