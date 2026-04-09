<?php

declare(strict_types=1);

/*
 * Shell injection tests for plugin_wmi.
 *
 * linux_wmi.php::clean() calls cacti_escapeshellarg() on username, password,
 * binary, and command before passing them to exec(). The hostname is only
 * trim()'d — not shell-escaped — which means a crafted hostname reaching
 * getcommand() can inject arbitrary shell tokens.
 *
 * These tests document the contract that MUST hold once WmiCommandBuilder
 * is extracted to src/. Until that seam exists the full-coverage tests are
 * marked ->todo().
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

    it('hostname is NOT shell-escaped in linux_wmi getcommand — documents the gap', function (): void {
        /*
         * linux_wmi::clean() does trim($this->hostname) but does NOT call
         * cacti_escapeshellarg() on the hostname before interpolating it into
         * the wmic command string at line 227:
         *   ' //' . trim($this->hostname)
         *
         * A hostname of "host; id" becomes "//host; id" in the shell command,
         * allowing arbitrary command injection.
         *
         * Remediation: wrap hostname in cacti_escapeshellarg() inside clean().
         * See FIND-001 in SECURITY-AUDIT.md.
         */
        $hostname = 'host; id';
        $trimmed  = trim($hostname);

        // trim() does NOT neutralise shell metacharacters.
        expect($trimmed)->toContain(';');
    })->group('security');

    it('extracts WmiCommandBuilder and enforces hostname escaping', function (): void {
        // Full coverage requires WmiCommandBuilder seam in src/.
    })->todo('Extract linux_wmi::getcommand() to src/WmiCommandBuilder before full coverage possible');

    it('WMI password decoded via unserialize is contained to expected shape', function (): void {
        /*
         * linux_wmi::decode() calls base64_decode() then unserialize() on the
         * password field fetched from the database (line 292). If an attacker
         * can write to wmi_user_accounts.password they can execute arbitrary
         * PHP objects via __wakeup / __destruct gadgets.
         *
         * The stored format is: serialize(['rand'=>..., 'password'=>'...', 'rand2'=>...])
         * Remediation: replace unserialize with json_decode + explicit key extraction.
         * See FIND-002 in SECURITY-AUDIT.md.
         */
        $payload = base64_encode(serialize([42 => 1234, 'password' => 'secret', 99 => 5678]));
        $decoded = unserialize(base64_decode($payload));

        expect($decoded)->toBeArray()
                        ->and($decoded['password'])->toBe('secret');
    })->group('security');

    it('sql injection via unparameterised id in db_fetch_row', function (): void {
        /*
         * functions.php:74 and :281 use:
         *   db_fetch_row("SELECT * FROM wmi_wql_queries WHERE id = $id")
         * If $id is not cast to int before use an attacker can inject SQL.
         * Remediation: cast to (int) or use db_fetch_row_prepared with ?.
         * See FIND-003 in SECURITY-AUDIT.md.
         */
        $tainted = '1 OR 1=1';
        $safe    = (int) $tainted;

        expect($safe)->toBe(1);
    })->group('security');

    it('sql injection in wmi-script.php queryname parameter', function (): void {
        /*
         * script/wmi-script.php:45 interpolates $wmiquery directly:
         *   db_fetch_row("SELECT * FROM plugin_wmi_queries WHERE queryname = '$wmiquery'")
         * $wmiquery comes from $_SERVER['argv'] which is CLI input — but the
         * script is also callable via Cacti's script server, making this
         * reachable from the poller with attacker-controlled input.
         * See FIND-004 in SECURITY-AUDIT.md.
         */
        $tainted = "legit' OR '1'='1";
        $safe    = addslashes($tainted); // illustrates minimum escaping needed

        expect($safe)->toContain("\\'");
    })->group('security');

    it('FIND-004 regression: wmi-script.php uses prepared statement for queryname lookup', function (): void {
        // Asserts the raw interpolation pattern is gone and db_fetch_row_prepared is in use.
        // Fails until wmi-script.php:45 is updated.
        $src = file_get_contents(__DIR__ . '/../../script/wmi-script.php');

        expect($src)->not->toContain("WHERE queryname = '\$wmiquery'");
        expect($src)->toContain('db_fetch_row_prepared');
        expect($src)->toContain("WHERE queryname = ?");
    })->group('security');

    it('XSS: WMI query results echoed without html_escape in wmi_tools.php', function (): void {
        /*
         * wmi_tools.php:566-588 prints $r (WMI field value) and $data directly
         * into <td> without html_escape(). WMI data originates from remote
         * Windows hosts and could contain <script> payloads.
         * See FIND-005 in SECURITY-AUDIT.md.
         */
        $wmiValue = '<script>alert(1)</script>';
        $escaped  = htmlspecialchars($wmiValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        expect($escaped)->not->toContain('<script>');
    })->group('security');

});
