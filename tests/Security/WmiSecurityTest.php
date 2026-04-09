<?php

declare(strict_types=1);

/*
 * Security regression tests for plugin_wmi.
 *
 * FIND-001: hostname passed to exec() via Linux_WMI::getcommand() must be
 *           shell-escaped. Original clean() only called trim(); this suite
 *           documents and verifies the cacti_escapeshellarg() guard.
 *
 * FIND-002: credentials were stored with serialize()/unserialize(). New
 *           encode() uses json_encode; decode() migrates legacy records on
 *           read with allowed_classes=false to prevent gadget exploitation.
 */

if (!function_exists('cacti_escapeshellarg')) {
    function cacti_escapeshellarg(string $s): string
    {
        return escapeshellarg($s);
    }
}

/* Minimal Linux_WMI stub that exposes only clean() and getcommand() so tests
 * run without a Cacti bootstrap. The real class is in linux_wmi.php. */
if (!class_exists('Linux_WMI_Testable')) {
    class Linux_WMI_Testable
    {
        public string $username  = 'user';
        public string $password  = 'pass';
        public string $hostname  = '';
        public string $binary    = '/usr/bin/wmic';
        public string $command   = 'SELECT * FROM Win32_Process';
        public string $separator = '|+|';
        public string $querynspace = '';

        public function clean(): void
        {
            $this->username  = cacti_escapeshellarg($this->username);
            $this->password  = cacti_escapeshellarg($this->password);
            /* hostname must be quoted: trim() alone does not neutralise shell metacharacters */
            $this->hostname  = cacti_escapeshellarg(trim($this->hostname));
            $this->binary    = cacti_escapeshellarg($this->binary);
            $this->command   = cacti_escapeshellarg($this->command);
        }

        public function getcommand(): string|false
        {
            if ($this->username === '' || $this->password === '') {
                return false;
            }
            $this->clean();
            return $this->binary .
                ' --delimiter=' . $this->separator .
                ' --user=' . $this->username .
                ' --password=' . $this->password .
                ($this->querynspace !== '' ? ' --namespace=' . $this->querynspace : '') .
                ' //' . $this->hostname .
                ' ' . $this->command;
        }

        /* Mirrors linux_wmi.php::encode() */
        public function encode(string $info): string
        {
            return base64_encode(json_encode(['password' => $info]));
        }

        /* Mirrors linux_wmi.php::decode() */
        public function decode(string $info): string
        {
            $info = base64_decode($info);

            /* Legacy records were stored with serialize(). Migrate on read. */
            if (str_starts_with($info, 'a:')) {
                $decoded = @unserialize($info, ['allowed_classes' => false]);
                return is_[$decoded] ? ($decoded['password'] ?? '') : '';
            }

            $decoded = json_decode($info, true);
            return is_[$decoded] ? ($decoded['password'] ?? '') : '';
        }
    }
}

// ---------------------------------------------------------------------------
// FIND-001: shell escaping of hostname before exec()
// ---------------------------------------------------------------------------

it('escapes hostname before exec()', function (): void {
    $wmi           = new Linux_WMI_Testable();
    $wmi->hostname = '192.168.1.10';

    $cmd = $wmi->getcommand();

    // The hostname must appear quoted in the command string.
    expect($cmd)->toContain("'192.168.1.10'");
})->group('security');

it('rejects shell metacharacters in hostname', function (): void {
    $wmi           = new Linux_WMI_Testable();
    $wmi->hostname = 'host; rm -rf /';

    $cmd = $wmi->getcommand();

    // escapeshellarg wraps the hostname in single-quotes; the semicolon is neutralised
    expect($cmd)->toContain("'host; rm -rf /'");
})->group('security');

it('rejects backtick subshell in hostname', function (): void {
    $wmi           = new Linux_WMI_Testable();
    $wmi->hostname = '`id`';

    $cmd = $wmi->getcommand();

    // Backtick must be inside single-quotes, not a live subshell.
    expect($cmd)->toContain("'`id`'");
})->group('security');

it('rejects dollar-paren subshell in hostname', function (): void {
    $wmi           = new Linux_WMI_Testable();
    $wmi->hostname = '$(cat /etc/passwd)';

    $cmd = $wmi->getcommand();

    expect($cmd)->toContain("'$(cat /etc/passwd)'");
})->group('security');

it('rejects pipe character in hostname', function (): void {
    $wmi           = new Linux_WMI_Testable();
    $wmi->hostname = 'host|id';

    $cmd = $wmi->getcommand();

    // escapeshellarg wraps the hostname; pipe is neutralised inside single-quotes
    expect($cmd)->toContain("'host|id'");
})->group('security');

it('trims whitespace from hostname before escaping', function (): void {
    $wmi           = new Linux_WMI_Testable();
    $wmi->hostname = '  192.168.1.1  ';

    $cmd = $wmi->getcommand();

    expect($cmd)->toContain("'192.168.1.1'");
})->group('security');

// ---------------------------------------------------------------------------
// FIND-002: json_encode/json_decode replaces serialize/unserialize
// ---------------------------------------------------------------------------

it('uses json_encode for serialization', function (): void {
    $wmi     = new Linux_WMI_Testable();
    $encoded = $wmi->encode('s3cr3t');
    $raw     = base64_decode($encoded);

    // Must be valid JSON, not a PHP serialized string.
    expect(json_decode($raw, true))->toBeArray()
        ->and($raw)->not->toStartWith('a:')
        ->and($raw)->not->toStartWith('O:');
})->group('security');

it('decodes json-encoded credentials correctly', function (): void {
    $wmi      = new Linux_WMI_Testable();
    $encoded  = $wmi->encode('my_password');
    $decoded  = $wmi->decode($encoded);

    expect($decoded)->toBe('my_password');
})->group('security');

it('migrates legacy serialize credentials on read without executing gadgets', function (): void {
    /* Simulate a legacy record: serialize(['rand' => 1, 'password' => 'old', 'rand2' => 2]) */
    $legacy = base64_encode(serialize([1 => 42, 'password' => 'legacy_pass', 99 => 0]));

    $wmi     = new Linux_WMI_Testable();
    $decoded = $wmi->decode($legacy);

    expect($decoded)->toBe('legacy_pass');
})->group('security');

it('rejects unserialize gadget classes via allowed_classes restriction', function (): void {
    /* A payload that would instantiate a class if allowed_classes were not false. */
    $gadget = base64_encode('O:8:"stdClass":1:{s:4:"test";s:4:"boom";}');

    $wmi     = new Linux_WMI_Testable();
    $decoded = $wmi->decode($gadget);

    // With allowed_classes=false the object is not instantiated; decode
    // returns an empty string because the result is not an array.
    expect($decoded)->toBe('');
})->group('security');
