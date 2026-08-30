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
 * Default transport: the Linux `wmic` client invoked through the shell.
 */
class Wmic_Shell_Transport implements Wmi_Transport {
	private ?string $error = null;

	public function query(Wmi_Request $request): array|false {
		$this->error = null;

		$output = [];
		$status = 0;

		exec($this->build_command($request), $output, $status);

		if ($status !== 0) {
			$this->error = 'ERROR: ' . implode('<br>', $output);

			return false;
		}

		if (count($output) === 0) {
			$this->error = 'ERROR: WMI Returned no Data';

			return false;
		}

		return $output;
	}

	public function error(): ?string {
		return $this->error;
	}

	/**
	 * Build the wmic command line. Every device-influenced value is shell
	 * escaped; the hostname and namespace are additionally stripped of cmd.exe
	 * metacharacters on win32. The delimiter is quoted so its pipe characters
	 * are not read as a shell pipeline.
	 */
	public function build_command(Wmi_Request $request): string {
		$namespace = $request->namespace !== ''
			? ' --namespace=' . cacti_escapeshellarg($this->strip_metachars($request->namespace))
			: '';

		return cacti_escapeshellarg($request->binary) .
			' --delimiter=' . cacti_escapeshellarg($request->separator) .
			' --user=' . cacti_escapeshellarg($request->username) .
			' --password=' . cacti_escapeshellarg($request->password) .
			$namespace .
			' //' . cacti_escapeshellarg($this->strip_metachars(trim($request->hostname))) .
			' ' . cacti_escapeshellarg($request->query);
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
		    $params['Namespace'] = ($env:WMI_NS -replace '\\', '/')
		}
		if (![string]::IsNullOrEmpty($env:WMI_HOST)) {
		    $secure = ConvertTo-SecureString $env:WMI_PASS -AsPlainText -Force
		    $params['ComputerName'] = $env:WMI_HOST
		    $params['Credential']   = New-Object System.Management.Automation.PSCredential($env:WMI_USER, $secure)
		}
		$items = @(Get-CimInstance @params)
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
			$detail      = $result['stderr'] !== '' ? $result['stderr'] : implode('<br>', $result['stdout']);
			$this->error = 'ERROR: ' . trim($detail);

			return false;
		}

		if (count($result['stdout']) === 0) {
			$this->error = 'ERROR: WMI Returned no Data';

			return false;
		}

		return $result['stdout'];
	}

	public function error(): ?string {
		return $this->error;
	}

	private function default_runner(): callable {
		return function (string $binary, array $args, string $stdin, array $env): array {
			$descriptors = [
				0 => ['pipe', 'r'],
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			];

			// proc_open replaces the environment wholesale, so merge our values
			// onto the inherited one to keep PATH and friends.
			$child_env = array_merge(getenv(), $env);

			$process = proc_open(array_merge([$binary], $args), $descriptors, $pipes, null, $child_env);

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
			fn (string $line): array => explode($this->separator, $line),
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
		// allowed_classes => false forbids object instantiation, so no gadget
		// chain can run; the blob is our own base64(serialize()) from encode().
		// nosemgrep: php.lang.security.unserialize-use.unserialize-use
		$decoded = unserialize(base64_decode($info, true), ['allowed_classes' => false]);

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
			$this->separator,
			$this->binary
		);
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
