<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The wmic transport hands the credentials to wmic in a private file
 * (--authentication-file), keeps stdout and stderr apart and stops a wmic that
 * does not finish. A stand-in executable records what it was given.
 */

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($string, $quote = true) {
		return escapeshellarg($string);
	}
}

require_once __DIR__ . '/../../includes/linux_wmi.php';

/** A transport that fails where the real one cannot be made to fail. */
class Wmi_Test_Failing_Transport extends Wmic_Shell_Transport {
	public const NO_FILE    = 'no_file';
	public const SHORT_FILE = 'short_file';

	public function __construct(private string $failure, string $temp_dir) {
		parent::__construct(5, $temp_dir);
	}

	protected function open_new_file(string $file) {
		if ($this->failure === self::NO_FILE) {
			return false;
		}

		if ($this->failure === self::SHORT_FILE) {
			// A stream that cannot be written to, so the write comes up short.
			return fopen('php://memory', 'r');
		}

		return parent::open_new_file($file);
	}
}

/**
 * Create a stand-in wmic and the directory it records into.
 *
 * @param array<int, string> $lines stdout lines
 *
 * @return array{binary: string, rec: string, tmp: string}
 */
function wmi_authtest_env(array $lines = [], int $status = 0, string $stderr = '', int $sleep = 0): array {
	$root = sys_get_temp_dir() . '/wmi-authtest-' . bin2hex(random_bytes(6));
	$rec  = $root . '/rec';
	$tmp  = $root . '/tmp';

	mkdir($root, 0700);
	mkdir($rec, 0700);
	mkdir($tmp, 0700);

	file_put_contents($rec . '/out', $lines === [] ? '' : implode("\n", $lines) . "\n");
	file_put_contents($rec . '/status', (string) $status);
	file_put_contents($rec . '/stderr', $stderr);
	file_put_contents($rec . '/sleep', (string) $sleep);

	file_put_contents($root . '/wmic', <<<'SH'
#!/bin/sh
REC="$(dirname "$0")/rec"
AUTH=""
for a in "$@"; do
  case "$a" in
    --authentication-file=*) AUTH="${a#--authentication-file=}" ;;
  esac
done
printf '%s\n' "$@" > "$REC/argv"
if [ -n "$AUTH" ]; then
  cp "$AUTH" "$REC/auth_content"
  printf '%s' "$AUTH" > "$REC/auth_path"
  stat -c '%a' "$AUTH" > "$REC/auth_mode" 2>/dev/null || stat -f '%Lp' "$AUTH" > "$REC/auth_mode"
  stat -c '%a' "$(dirname "$AUTH")" > "$REC/dir_mode" 2>/dev/null || stat -f '%Lp' "$(dirname "$AUTH")" > "$REC/dir_mode"
fi
if [ -s "$REC/stderr" ]; then cat "$REC/stderr" >&2; fi
if [ "$(cat "$REC/sleep")" != "0" ]; then exec sleep "$(cat "$REC/sleep")"; fi
cat "$REC/out"
exit "$(cat "$REC/status")"
SH
	);
	chmod($root . '/wmic', 0700);

	return ['binary' => $root . '/wmic', 'rec' => $rec, 'tmp' => $tmp];
}

function wmi_authtest_request(array $env, string $user = 'u', string $password = 'p'): Wmi_Request {
	return new Wmi_Request('host.example.com', $user, $password, 'root\\CIMV2', 'SELECT 1', '|+|', $env['binary']);
}

function wmi_authtest_recorded(array $env, string $name): string {
	return is_file($env['rec'] . '/' . $name) ? (string) file_get_contents($env['rec'] . '/' . $name) : '';
}

afterAll(function () {
	foreach (glob(sys_get_temp_dir() . '/wmi-authtest-*') ?: [] as $root) {
		foreach (['rec', 'tmp'] as $sub) {
			foreach (glob($root . '/' . $sub . '/*') ?: [] as $entry) {
				if (is_dir($entry)) {
					array_map('unlink', glob($entry . '/*') ?: []);
					rmdir($entry);
				} else {
					unlink($entry);
				}
			}

			@rmdir($root . '/' . $sub);
		}

		@unlink($root . '/wmic');
		@rmdir($root);
	}
});

beforeEach(function () {
	$GLOBALS['__test_cacti_log'] = array();
});

it('keeps the credentials out of the arguments and gives wmic a private file', function () {
	$env = wmi_authtest_env(['CLASS', 'Name', 'A']);
	$t   = new Wmic_Shell_Transport(120, $env['tmp']);

	$result = $t->query(wmi_authtest_request($env, 'DOM\\svc', 'S3cret pass'));
	$argv   = wmi_authtest_recorded($env, 'argv');

	expect($result)->toBe(['CLASS', 'Name', 'A']);
	expect($t->error())->toBeNull();
	expect($argv)->not->toContain('--user');
	expect($argv)->not->toContain('--password');
	expect($argv)->not->toContain('S3cret');
	expect($argv)->toContain('--authentication-file=');
	expect($argv)->toContain('//host.example.com');
	expect(wmi_authtest_recorded($env, 'auth_content'))->toBe("username=svc\npassword=S3cret pass\ndomain=DOM\n");
	expect(trim(wmi_authtest_recorded($env, 'auth_mode')))->toBe('600');
	expect(trim(wmi_authtest_recorded($env, 'dir_mode')))->toBe('700');
});

it('removes the credentials file and its directory after a run', function () {
	$env = wmi_authtest_env(['CLASS']);

	(new Wmic_Shell_Transport(120, $env['tmp']))->query(wmi_authtest_request($env));

	expect(file_exists(wmi_authtest_recorded($env, 'auth_path')))->toBeFalse();
	expect(glob($env['tmp'] . '/*'))->toBe(array());
});

it('removes the credentials file when wmic fails or times out', function (array $env, int $timeout) {
	$t = new Wmic_Shell_Transport($timeout, $env['tmp']);

	expect($t->query(wmi_authtest_request($env)))->toBeFalse();
	expect(wmi_authtest_recorded($env, 'auth_path'))->not->toBe('');
	expect(glob($env['tmp'] . '/*'))->toBe(array());
})->with([
	'non-zero exit' => fn () => [wmi_authtest_env(['NTSTATUS: denied'], 1), 120],
	'timeout'       => fn () => [wmi_authtest_env([], 0, '', 10), 1],
]);

it('writes passwords with special characters unchanged', function (string $password) {
	$env = wmi_authtest_env(['CLASS']);

	(new Wmic_Shell_Transport(120, $env['tmp']))->query(wmi_authtest_request($env, 'svc', $password));

	expect(wmi_authtest_recorded($env, 'auth_content'))->toBe("username=svc\npassword=" . $password . "\n");
})->with([
	'equals'       => 'a=b=c',
	'quotes'       => 'it\'s "quoted"',
	'spaces'       => 'two  words ',
	'shell'        => '$(x) `y` ; | & > <',
	'backslash'    => 'a\\b\\\\c',
	'hash'         => '#not-a-comment',
	'unicode'      => "p\xc3\xa4ss",
]);

it('splits DOMAIN/user and leaves a UPN user name whole', function (string $user, string $expected) {
	$env = wmi_authtest_env(['CLASS']);

	(new Wmic_Shell_Transport(120, $env['tmp']))->query(wmi_authtest_request($env, $user, 'pw'));

	expect(wmi_authtest_recorded($env, 'auth_content'))->toBe($expected);
})->with([
	'backslash' => ['DOM\\svc', "username=svc\npassword=pw\ndomain=DOM\n"],
	'slash'     => ['DOM/svc', "username=svc\npassword=pw\ndomain=DOM\n"],
	'upn'       => ['svc@corp.example', "username=svc@corp.example\npassword=pw\n"],
	'plain'     => ['svc', "username=svc\npassword=pw\n"],
]);

it('refuses credentials with line breaks and never starts wmic', function (string $user, string $password) {
	$env = wmi_authtest_env(['CLASS']);
	$t   = new Wmic_Shell_Transport(120, $env['tmp']);

	expect($t->query(wmi_authtest_request($env, $user, $password)))->toBeFalse();
	expect($t->error())->toBe('ERROR: WMI credentials must not contain line breaks');
	expect(wmi_authtest_recorded($env, 'argv'))->toBe('');
	expect(glob($env['tmp'] . '/*'))->toBe(array());
})->with([
	'newline in password'  => ['u', "a\nb"],
	'return in password'   => ['u', "a\rb"],
	'nul in password'      => ['u', "a\0b"],
	'newline in user'      => ["u\nusername=x", 'p'],
]);

it('falls back to arguments with a warning when the credentials file cannot be made', function (string $failure) {
	$env = wmi_authtest_env(['CLASS']);
	$t   = new Wmi_Test_Failing_Transport($failure, $env['tmp']);

	expect($t->query(wmi_authtest_request($env, 'svc', 'pw')))->toBe(['CLASS']);
	expect(wmi_authtest_recorded($env, 'argv'))->toContain('--user=svc');
	expect(wmi_authtest_recorded($env, 'argv'))->toContain('--password=pw');
	expect($GLOBALS['__test_cacti_log'][0])->toContain('Unable to write the wmic credentials file');
	expect(glob($env['tmp'] . '/*'))->toBe(array());
})->with([Wmi_Test_Failing_Transport::NO_FILE, Wmi_Test_Failing_Transport::SHORT_FILE]);

it('falls back to arguments with a warning when no private directory can be made', function () {
	$env = wmi_authtest_env(['CLASS']);
	$t   = new Wmic_Shell_Transport(120, $env['tmp'] . '/missing/deeper');

	expect($t->query(wmi_authtest_request($env, 'svc', 'pw')))->toBe(['CLASS']);
	expect(wmi_authtest_recorded($env, 'argv'))->toContain('--user=svc');
	expect($GLOBALS['__test_cacti_log'][0])->toContain('Unable to create a private directory');
});

it('builds the legacy command line without a credentials file', function () {
	$env = wmi_authtest_env();
	$cmd = (new Wmic_Shell_Transport())->build_command(wmi_authtest_request($env, 'svc', 'pw'));

	expect($cmd)->toContain(" --user='svc' --password='pw'");
	expect($cmd)->not->toContain('--authentication-file');
});
