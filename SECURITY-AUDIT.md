# Security Audit: plugin_wmi

**Auditor:** Static analysis (grep + manual review)
**Date:** 2026-03-09
**Scope:** All PHP files in plugin root and subdirectories
**Method:** Pattern grep + manual code review of execution paths

---

## Summary

plugin_wmi executes WMI queries against remote Windows hosts by invoking the
`wmic` binary via `exec()`. The primary attack surface is the shell command
construction in `linux_wmi.php`. Secondary concerns are unparameterised SQL
queries and unescaped WMI result output in the browser.

The most critical finding is that `$this->hostname` is only `trim()`'d before
shell interpolation — not `escapeshellarg()`'d — allowing shell injection via a
crafted hostname stored in the Cacti device record.

Credential storage uses `base64(serialize(...))` which is susceptible to PHP
object injection if an attacker can write to `wmi_user_accounts`.

---

## Findings

### FIND-001

| Field | Value |
|---|---|
| Category | Shell Injection |
| Severity | **HIGH** |
| Confidence | HIGH |
| File | `linux_wmi.php` |
| Line | 227, 261 |
| Evidence | `' //' . trim($this->hostname)` — hostname is `trim()`'d only; `clean()` calls `cacti_escapeshellarg()` on username, password, binary, command but skips hostname |

**Description:** `getcommand()` builds the wmic shell command by directly
interpolating `trim($this->hostname)` into the argument string. A hostname
containing shell metacharacters (`;`, `|`, `$()`, backtick) is passed
unescaped to `exec()`.

**Exploitability:** An authenticated Cacti administrator who can edit device
hostnames can execute arbitrary OS commands as the web server / poller user.
In environments with shared admin access or SSRF, the bar may be lower.

**Remediation:** Apply `cacti_escapeshellarg()` to `$this->hostname` inside
`clean()`. Validate that the hostname is a valid FQDN or IP before use.

**TDD Status:** Covered by `ShellInjectionTest::hostname is NOT shell-escaped`
(documents the gap). Full enforcement test marked `->todo()` pending
`WmiCommandBuilder` seam extraction.

---

### FIND-002

| Field | Value |
|---|---|
| Category | PHP Object Injection |
| Severity | **HIGH** |
| Confidence | MEDIUM |
| File | `linux_wmi.php` |
| Line | 292 |
| Evidence | `$info = unserialize($info);` inside `decode()` operating on a DB-sourced value |

**Description:** `decode()` calls `base64_decode()` then `unserialize()` on
the `password` column from `wmi_user_accounts`. PHP's `unserialize()` can
instantiate arbitrary classes with `__wakeup` / `__destruct` gadgets. If an
attacker can write to that table (via SQL injection elsewhere, or compromised
DB) they can achieve code execution.

**Exploitability:** Requires prior write access to the database. Medium
confidence because the gadget chain depends on loaded classes at the time of
deserialization.

**Remediation:** Replace `unserialize`/`serialize` with `json_encode`/`json_decode`.
The password array shape is fixed (`['password' => '...']`); JSON is sufficient.

**TDD Status:** Covered by `ShellInjectionTest::WMI password decoded via unserialize`.

---

### FIND-003

| Field | Value |
|---|---|
| Category | SQL Injection |
| Severity | **MEDIUM** |
| Confidence | HIGH |
| File | `functions.php` |
| Lines | 74, 177, 281 |
| Evidence | `db_fetch_row("SELECT * FROM wmi_wql_queries WHERE id = $id")` — `$id` is not cast or parameterised |

**Description:** Three `db_fetch_row`/`db_fetch_assoc` calls interpolate `$id`
or `$input` directly into query strings without casting to `int` or using
`db_fetch_row_prepared`. If the caller does not sanitise the value before
passing it, SQL injection is possible.

**Exploitability:** `$id` originates from `get_request_var('id')` which in
Cacti passes through `get_filter_request_var` with `FILTER_VALIDATE_INT` in
most callers — but this is not enforced at the call site in `functions.php`.
Risk is lower than a direct `$_GET` interpolation but still a hardening gap.

**Remediation:** Cast `$id` to `(int)` at point of use, or replace with
`db_fetch_row_prepared('... WHERE id = ?', [$id])`.

**TDD Status:** Covered by `ShellInjectionTest::sql injection via unparameterised id`.

---

### FIND-004

| Field | Value |
|---|---|
| Category | SQL Injection |
| Severity | **HIGH** |
| Confidence | HIGH |
| File | `script/wmi-script.php` |
| Line | 45 |
| Evidence | `db_fetch_row("SELECT * FROM plugin_wmi_queries WHERE queryname = '$wmiquery'")` |

**Description:** `$wmiquery` is taken from `$_SERVER['argv']` (script server
argument) and interpolated directly into a SQL string without escaping. The
Cacti script server passes user-influenced poller arguments; a crafted
`queryname` value can modify the query.

**Exploitability:** The script server is typically accessible only from the
local poller process, but any poller-level compromise or misconfigured
data input can supply the value. Confidence is high because there is no
escaping at this call site.

**Remediation:** Replace with `db_fetch_row_prepared('... WHERE queryname = ?', [$wmiquery])`.

**TDD Status:** Covered by `ShellInjectionTest::sql injection in wmi-script.php`.

---

### FIND-005

| Field | Value |
|---|---|
| Category | Cross-Site Scripting (Stored) |
| Severity | **MEDIUM** |
| Confidence | HIGH |
| File | `wmi_tools.php` |
| Lines | 566, 569, 581, 588, 628, 631 |
| Evidence | `print "<td>" . $data . "</td>"` and `print "<td>" . $r . "</td>"` — WMI result values printed without `html_escape()` |

**Description:** The WMI Tools page renders query results fetched live from
remote Windows hosts. Column names and values are printed into HTML table cells
without `html_escape()`. A Windows host returning a WMI value containing
`<script>alert(1)</script>` in a property (e.g. `Win32_Process.Description`)
would execute in the operator's browser.

**Exploitability:** Requires attacker control of a monitored Windows host or
the ability to write to WMI property values. Stored XSS affecting Cacti
administrators.

**Remediation:** Wrap all WMI result output in `html_escape()` before printing.

**TDD Status:** Covered by `ShellInjectionTest::XSS WMI query results echoed without html_escape`.

---

### FIND-006

| Field | Value |
|---|---|
| Category | SQL Injection |
| Severity | **LOW** |
| Confidence | MEDIUM |
| File | `functions.php` |
| Line | 61 |
| Evidence | `db_fetch_cell("SELECT COUNT(*) FROM wmi_wql_queries WHERE query RLIKE '^FROM\s$token$+'")` |

**Description:** `$token` is derived from `preg_split` on a WQL query string
stored in the database — not directly from user input — but it is interpolated
into a RLIKE expression without parameterisation. Risk is low given the
indirect origin but violates defence-in-depth.

**Remediation:** Use `db_fetch_cell_prepared` with a `?` placeholder.

**TDD Status:** Not yet covered; lower priority.

---

### FIND-007

| Field | Value |
|---|---|
| Category | SQL Injection |
| Severity | **LOW** |
| Confidence | MEDIUM |
| File | `poller_wmi.php` |
| Lines | 194, 213, 239, 240, 299, 300 |
| Evidence | Multiple `db_execute` / `db_fetch_cell` calls interpolating `$key`, `$seed`, `$device['host_id']` directly |

**Description:** Poller-internal variables (`$key` = `getmypid()`, `$seed`,
`$device['host_id']`) are interpolated without parameterisation. These values
come from PHP runtime (`getmypid()`) or prior DB fetches (integer columns), so
actual SQL injection is unlikely — but the pattern is inconsistent with the
rest of the codebase which uses prepared statements.

**Remediation:** Consistent use of `db_execute_prepared` with `?` placeholders.

**TDD Status:** Not yet covered; hardening-only.

---

## Unknowns

- Whether the COM-based Windows execution path (`wmi_tools.php:601`) has the
  same hostname-escaping issue. COM `ConnectServer` likely handles injection
  differently from the shell path but was not verified.
- Whether `wmi_accounts.php` password field is validated before being passed
  to `encode()` / stored.

## Blind Spots

- **Cannot verify runtime WMI execution without a live Windows host and wmic
  binary.** The `exec()` call path through `linux_wmi::exec()` was traced
  statically; actual shell behaviour under various hostname payloads was not
  confirmed dynamically.
- Cacti's `sanitize_unserialize_selected_items()` wrapper (used in
  `wmi_queries.php:127` and `wmi_accounts.php:103`) was not reviewed; assumed
  to be safe per Cacti core.

## Seams Needed

To achieve full automated coverage the following refactors are required:

1. **`src/WmiCommandBuilder.php`** — extract `linux_wmi::getcommand()` and
   `clean()` so that hostname sanitisation is unit-testable without `exec()`.
2. **`src/WmiCredentialStore.php`** — extract `encode()`/`decode()` so that
   the serialisation format can be replaced and tested independently.
3. **`src/WmiQueryRepository.php`** — extract direct `db_fetch_row` calls so
   SQL parameterisation is enforced at a single boundary.
