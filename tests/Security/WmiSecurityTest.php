<?php

declare(strict_types=1);

/*
 * Security regression tests for plugin_wmi.
 *
 * FIND-001: hostname passed to exec() via Linux_WMI::getcommand() must be
 *           shell-escaped. Original clean() only called trim(); this suite
 *           verifies the cacti_escapeshellarg() guard on the real class.
 *
 * FIND-002: credentials were stored with serialize()/unserialize(). New
 *           encode() uses json_encode; decode() migrates legacy records on
 *           read with allowed_classes=false to prevent gadget exploitation.
 *
 * These tests load the real linux_wmi.php and instantiate the real
 * Linux_WMI class so coverage is against production code. FIND-001 tests
 * skip when cacti_escapeshellarg() is not available (no Cacti bootstrap)
 * rather than polyfilling a security-sensitive core function inside the
 * test. FIND-002 tests run unconditionally because encode()/decode() have
 * no Cacti dependency.
 */

if (!class_exists('Linux_WMI', false)) {
    require_once __DIR__ . '/../../linux_wmi.php';
}

$needsCactiBootstrap = static fn (): bool => !function_exists('cacti_escapeshellarg');
$bootstrapReason     = 'Cacti bootstrap required: cacti_escapeshellarg() not loaded';

// ---------------------------------------------------------------------------
// FIND-001: shell escaping of hostname before exec()
// ---------------------------------------------------------------------------

it('escapes hostname before exec()', function (): void {
    $wmi           = new Linux_WMI();
    $wmi->username = 'user';
    $wmi->password = 'pass';
    $wmi->hostname = '192.168.1.10';
    $wmi->binary   = '/usr/bin/wmic';
    $wmi->command  = 'SELECT * FROM Win32_Process';

    expect($wmi->getcommand())->toContain("'192.168.1.10'");
})->skip($needsCactiBootstrap, $bootstrapReason)->group('security');

it('rejects shell metacharacters in hostname', function (): void {
    $wmi           = new Linux_WMI();
    $wmi->username = 'user';
    $wmi->password = 'pass';
    $wmi->hostname = 'host; rm -rf /';
    $wmi->binary   = '/usr/bin/wmic';
    $wmi->command  = 'SELECT * FROM Win32_Process';

    expect($wmi->getcommand())->toContain("'host; rm -rf /'");
})->skip($needsCactiBootstrap, $bootstrapReason)->group('security');

it('rejects backtick subshell in hostname', function (): void {
    $wmi           = new Linux_WMI();
    $wmi->username = 'user';
    $wmi->password = 'pass';
    $wmi->hostname = '`id`';
    $wmi->binary   = '/usr/bin/wmic';
    $wmi->command  = 'SELECT * FROM Win32_Process';

    expect($wmi->getcommand())->toContain("'`id`'");
})->skip($needsCactiBootstrap, $bootstrapReason)->group('security');

it('rejects dollar-paren subshell in hostname', function (): void {
    $wmi           = new Linux_WMI();
    $wmi->username = 'user';
    $wmi->password = 'pass';
    $wmi->hostname = '$(cat /etc/passwd)';
    $wmi->binary   = '/usr/bin/wmic';
    $wmi->command  = 'SELECT * FROM Win32_Process';

    expect($wmi->getcommand())->toContain("'$(cat /etc/passwd)'");
})->skip($needsCactiBootstrap, $bootstrapReason)->group('security');

it('rejects pipe character in hostname', function (): void {
    $wmi           = new Linux_WMI();
    $wmi->username = 'user';
    $wmi->password = 'pass';
    $wmi->hostname = 'host|id';
    $wmi->binary   = '/usr/bin/wmic';
    $wmi->command  = 'SELECT * FROM Win32_Process';

    expect($wmi->getcommand())->toContain("'host|id'");
})->skip($needsCactiBootstrap, $bootstrapReason)->group('security');

it('trims whitespace from hostname before escaping', function (): void {
    $wmi           = new Linux_WMI();
    $wmi->username = 'user';
    $wmi->password = 'pass';
    $wmi->hostname = '  192.168.1.1  ';
    $wmi->binary   = '/usr/bin/wmic';
    $wmi->command  = 'SELECT * FROM Win32_Process';

    expect($wmi->getcommand())->toContain("'192.168.1.1'");
})->skip($needsCactiBootstrap, $bootstrapReason)->group('security');

// ---------------------------------------------------------------------------
// FIND-002: json_encode/json_decode replaces serialize/unserialize
// ---------------------------------------------------------------------------

it('uses json_encode for serialization', function (): void {
    $wmi     = new Linux_WMI();
    $encoded = $wmi->encode('s3cr3t');
    $raw     = base64_decode($encoded);

    expect(json_decode($raw, true))->toBeArray()
        ->and($raw)->not->toStartWith('a:')
        ->and($raw)->not->toStartWith('O:');
})->group('security');

it('decodes json-encoded credentials correctly', function (): void {
    $wmi     = new Linux_WMI();
    $encoded = $wmi->encode('my_password');
    $decoded = $wmi->decode($encoded);

    expect($decoded)->toBe('my_password');
})->group('security');

it('migrates legacy serialize credentials on read without executing gadgets', function (): void {
    $legacy = base64_encode(serialize([1 => 42, 'password' => 'legacy_pass', 99 => 0]));

    $wmi     = new Linux_WMI();
    $decoded = $wmi->decode($legacy);

    expect($decoded)->toBe('legacy_pass');
})->group('security');

it('rejects unserialize gadget classes via allowed_classes restriction', function (): void {
    $gadget = base64_encode('O:8:"stdClass":1:{s:4:"test";s:4:"boom";}');

    $wmi     = new Linux_WMI();
    $decoded = $wmi->decode($gadget);

    expect($decoded)->toBe('');
})->group('security');
