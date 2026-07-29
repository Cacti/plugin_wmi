<?php

declare(strict_types=1);

/*
 * Shell injection tests for plugin_wmi.
 *
 * linux_wmi.php::clean() shell-escapes username, password, hostname, binary,
 * and command via cacti_escapeshellarg() before they reach exec(). Real
 * coverage of that guard (instantiating Linux_WMI and calling getcommand())
 * lives in WmiSecurityTest.php; the generic escapeshellarg() invariants below
 * are kept here as a baseline sanity check on PHP's own quoting behavior.
 */

describe('Shell command injection guard', function (): void {

    it('rejects shell metacharacters in WMI host parameter', function (): void {
        $malicious = 'host; rm -rf /';
        $safe      = escapeshellarg($malicious);

        // escapeshellarg wraps in single-quotes; metacharacters are neutralised, not stripped
        expect($safe)->toStartWith("'")->toEndWith("'");
    });

    it('rejects null bytes in command parameters', function (): void {
        $malicious = "host\x00injected";
        $safe      = str_replace("\x00", '', $malicious);

        expect($safe)->not->toContain("\x00");
    });

    it('rejects backtick subshell in hostname', function (): void {
        $malicious = '`id`';
        $safe      = escapeshellarg($malicious);

        expect($safe)->toStartWith("'")->toEndWith("'");
    });

    it('rejects dollar-paren subshell in hostname', function (): void {
        $malicious = '$(cat /etc/passwd)';
        $safe      = escapeshellarg($malicious);

        expect($safe)->toStartWith("'")->toEndWith("'");
    });

    it('rejects pipe character in username', function (): void {
        $malicious = 'user|id';
        $safe      = escapeshellarg($malicious);

        expect($safe)->toStartWith("'")->toEndWith("'");
    });

    it('rejects newline in password', function (): void {
        $malicious = "pass\nword";
        $safe      = escapeshellarg($malicious);

        // escapeshellarg wraps in single-quotes; the newline is literal but
        // contained — the key invariant is no unquoted shell separator.
        expect($safe)->toStartWith("'");
    });

    it('FIND-004 regression: wmi-script.php uses prepared statement for queryname lookup', function (): void {
        // Asserts the raw interpolation pattern is gone and db_fetch_row_prepared is in use.
        $src = file_get_contents(__DIR__ . '/../../script/wmi-script.php');

        expect($src)->not->toContain("WHERE queryname = '\$wmiquery'");
        expect($src)->toContain('db_fetch_row_prepared');
        expect($src)->toContain("WHERE queryname = ?");
    })->group('security');

});
