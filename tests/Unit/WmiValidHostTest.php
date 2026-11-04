<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * wmi_valid_host() checks what is typed into the Query Tool: IPv4 and IPv6
 * addresses, and host names under RFC 1123.
 */

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($string, $quote = true) {
		return escapeshellarg($string);
	}
}

require_once __DIR__ . '/../../includes/functions.php';

it('accepts IPv4 addresses', function (string $host) {
	expect(wmi_valid_host($host))->toBe($host);
})->with(['192.168.1.10', '10.0.0.1', '255.255.255.255', '0.0.0.0']);

it('accepts IPv6 addresses in compressed and expanded form', function (string $host) {
	expect(wmi_valid_host($host))->toBe($host);
})->with([
	'loopback'          => '::1',
	'unspecified'       => '::',
	'compressed'        => '2001:db8::1',
	'expanded'          => '2001:0db8:0000:0000:0000:0000:0000:0001',
	'link local'        => 'fe80::1',
	'mapped IPv4'       => '::ffff:192.0.2.1',
]);

it('removes the brackets from a bracketed IPv6 address', function () {
	expect(wmi_valid_host('[2001:db8::1]'))->toBe('2001:db8::1');
	expect(wmi_valid_host('[::1]'))->toBe('::1');
});

it('accepts short names, dotted names and names with any top level label', function (string $host) {
	expect(wmi_valid_host($host))->toBe($host);
})->with([
	'short'          => 'win-server',
	'upper case'     => 'WIN-SERVER',
	'single letter'  => 'a',
	'with digits'    => 'srv01',
	'fqdn'           => 'server.example.com',
	'local'          => 'server.corp.local',
	'long tld'       => 'server.example.international',
	'digits inside'  => '3com.example.org',
	'trailing dot'   => 'example.com.',
]);

it('accepts labels and names at the length limits', function () {
	$label = str_repeat('a', 63);
	$name  = $label . '.' . $label . '.' . $label . '.' . str_repeat('d', 61);

	expect(strlen($name))->toBe(253);
	expect(wmi_valid_host($label . '.example.com'))->toBe($label . '.example.com');
	expect(wmi_valid_host($name))->toBe($name);
	expect(wmi_valid_host($name . '.'))->toBe($name . '.');
});

it('rejects labels and names over the length limits', function () {
	$label = str_repeat('a', 63);

	expect(wmi_valid_host(str_repeat('a', 64) . '.example.com'))->toBeFalse();
	expect(wmi_valid_host($label . '.' . $label . '.' . $label . '.' . str_repeat('d', 62)))->toBeFalse();
});

it('rejects empty input and malformed names', function (string $host) {
	expect(wmi_valid_host($host))->toBeFalse();
})->with([
	'empty'              => '',
	'space'              => ' ',
	'inner space'        => 'host name',
	'leading hyphen'     => '-bad.example.com',
	'trailing hyphen'    => 'bad-.example.com',
	'underscore'         => 'bad_host',
	'empty label'        => 'a..b',
	'leading dot'        => '.example.com',
	'double trailing dot' => 'example.com..',
	'only a dot'         => '.',
	'non ascii'          => "\xc3\xbcn\xc3\xaf.example.com",
	'port'               => 'example.com:445',
	'slashes'            => '//host',
	'cidr'               => '1.2.3.4/24',
]);

it('rejects IPv6 zone ids and malformed brackets', function (string $host) {
	expect(wmi_valid_host($host))->toBeFalse();
})->with([
	'zone id'               => 'fe80::1%eth0',
	'bracketed zone id'     => '[fe80::1%eth0]',
	'numeric zone id'       => '::1%1',
	'bracketed IPv4'        => '[192.168.1.1]',
	'bracketed name'        => '[example.com]',
	'unclosed bracket'      => '[::1',
	'unopened bracket'      => '::1]',
	'text after bracket'    => '[::1]x',
	'empty brackets'        => '[]',
	'too many groups'       => '1:2:3:4:5:6:7:8:9',
]);

it('rejects malformed addresses that look like numbers', function (string $host) {
	expect(wmi_valid_host($host))->toBeFalse();
})->with(['999.1.1.1', '1.2.3', '1.2.3.4.5', '123', '010.0.0.1.1']);

it('rejects input that looks like an injection attempt', function (string $host) {
	expect(wmi_valid_host($host))->toBeFalse();
})->with([
	'semicolon'     => 'host; id',
	'and'           => 'host && id',
	'substitution'  => '$(id)',
	'backticks'     => '`id`',
	'option'        => '-oProxyCommand=x',
	'newline'       => "host\nname",
	'nul'           => "ho\0st",
	'pipe'          => 'host|id',
	'quote'         => "host'",
	'percent'       => '%USERNAME%',
]);

it('keeps a host that starts with a hyphen behind the // prefix in the wmic command', function () {
	$w           = new Linux_WMI('', new Wmic_Shell_Transport());
	$w->username = 'u';
	$w->password = 'p';
	$w->command  = 'SELECT 1';
	$w->hostname = '-oProxy';

	expect($w->getcommand())->toContain(" //'-oProxy' ");
});

it('passes an IPv6 address to wmic as one escaped argument after //', function () {
	$w           = new Linux_WMI('', new Wmic_Shell_Transport());
	$w->username = 'u';
	$w->password = 'p';
	$w->command  = 'SELECT 1';
	$w->hostname = '2001:db8::1';

	expect($w->getcommand())->toContain(" //'2001:db8::1' ");
});
