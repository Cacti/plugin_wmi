# Backlog: plugin_wmi

Items are ordered by priority within each type. Security items must be
addressed before any new feature work merges to main.

---

### Issue #1: security(shell): escape hostname before exec in linux_wmi::clean()

**Priority:** P0 — Critical
**Labels:** security, hardening, shell-injection
**Branch:** `hardening/wmi-hostname-escapeshellarg`
**Evidence:** `linux_wmi.php:227` — `' //' . trim($this->hostname)` passed to `exec()` without `cacti_escapeshellarg()`. FIND-001 in SECURITY-AUDIT.md.
**Acceptance criteria:**
- `clean()` applies `cacti_escapeshellarg()` to `$this->hostname`
- Hostname is validated as FQDN or IPv4/IPv6 before `clean()` is called
- `ShellInjectionTest` todo test passes without `->todo()`
- No regression in existing WMI poll results

**Dependencies:** Requires `src/WmiCommandBuilder` seam (Issue #7)

---

### Issue #2: security(crypto): replace unserialize with json_decode in linux_wmi::decode()

**Priority:** P0 — Critical
**Labels:** security, hardening, object-injection
**Branch:** `hardening/wmi-credential-json`
**Evidence:** `linux_wmi.php:292` — `unserialize(base64_decode($info))`. FIND-002 in SECURITY-AUDIT.md.
**Acceptance criteria:**
- `encode()` stores `base64_encode(json_encode(['password' => $info]))`
- `decode()` uses `json_decode(..., true)['password']`
- Migration script or upgrade hook converts existing rows in `wmi_user_accounts`
- `ShellInjectionTest::WMI password decoded via unserialize` updated to assert JSON path

**Dependencies:** None

---

### Issue #3: security(sql): parameterise db_fetch_row calls in functions.php

**Priority:** P1 — High
**Labels:** security, hardening, sql
**Branch:** `hardening/wmi-sql-parameterise-functions`
**Evidence:** `functions.php:74,177,281` — `$id` / `$input` interpolated. FIND-003 in SECURITY-AUDIT.md.
**Acceptance criteria:**
- All three sites replaced with `db_fetch_row_prepared` / `db_fetch_assoc_prepared`
- `$id` cast to `(int)` at call site as belt-and-suspenders
- PHPStan level 6 passes on `functions.php`

**Dependencies:** None

---

### Issue #4: security(sql): parameterise queryname in script/wmi-script.php

**Priority:** P1 — High
**Labels:** security, hardening, sql
**Branch:** `hardening/wmi-sql-script-queryname`
**Evidence:** `script/wmi-script.php:45` — `"... WHERE queryname = '$wmiquery'"`. FIND-004 in SECURITY-AUDIT.md.
**Acceptance criteria:**
- Replaced with `db_fetch_row_prepared('... WHERE queryname = ?', [$wmiquery])`
- `ShellInjectionTest::sql injection in wmi-script.php` passes without manual workaround

**Dependencies:** None

---

### Issue #5: security(xss): html_escape all WMI result output in wmi_tools.php

**Priority:** P1 — High
**Labels:** security, hardening, xss
**Branch:** `hardening/wmi-xss-tools-escape`
**Evidence:** `wmi_tools.php:566,569,581,588,628,631`. FIND-005 in SECURITY-AUDIT.md.
**Acceptance criteria:**
- All `$r`, `$data`, `$odata1[$index]`, `$indexes[$index]` wrapped in `html_escape()` before `print`
- `ShellInjectionTest::XSS WMI query results echoed without html_escape` passes

**Dependencies:** None

---

### Issue #6: test: bootstrap Pest 4 test suite

**Priority:** P1
**Labels:** test, dx
**Branch:** `test/wmi-pest-bootstrap`
**Evidence:** No `vendor/` exists; `composer install` required before any test run.
**Acceptance criteria:**
- `composer install` succeeds on PHP 8.4
- `vendor/bin/pest --list` shows all test files
- CI passes on first green run

**Dependencies:** Issues #3, #4, #5 (security tests need real seams)

---

### Issue #7: refactor: extract WmiCommandBuilder to src/

**Priority:** P2
**Labels:** refactor, testability
**Branch:** `refactor/wmi-command-builder`
**Evidence:** `linux_wmi.php::getcommand()` / `clean()` are untestable without running `exec()`. Seams Needed section of SECURITY-AUDIT.md.
**Acceptance criteria:**
- `src/WmiCommandBuilder.php` encapsulates command assembly
- `linux_wmi.php` delegates to `WmiCommandBuilder`
- All existing behaviour preserved
- Unit tests for hostname/username/password escaping pass at 100% coverage

**Dependencies:** Issue #1 (hostname escaping belongs in this class)

---

### Issue #8: refactor: isolate Cacti global functions behind interface

**Priority:** P2
**Labels:** refactor, testability
**Branch:** `refactor/wmi-cacti-globals-interface`
**Evidence:** `db_fetch_row`, `db_execute`, `read_config_option` called as globals throughout. Tests require stubs.
**Acceptance criteria:**
- `src/CactiDb.php` interface wrapping DB calls
- `tests/Helpers/CactiStubs.php` satisfies the interface in tests
- PHPStan strict rules pass on all `src/` classes

**Dependencies:** Issue #7

---

### Issue #9: ci: GitHub Actions workflow

**Priority:** P2
**Labels:** ci, dx
**Branch:** `ci/wmi-github-actions`
**Evidence:** `.github/workflows/ci.yml` scaffold created; requires `composer install` to be runnable.
**Acceptance criteria:**
- Workflow runs on push to `main` / PR
- PHP 8.4 matrix
- PHPStan + Pest + coverage gate at 80%
- Pinned to `actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683`

**Dependencies:** Issue #6

---

### Issue #10: docs: add SECURITY.md with vulnerability disclosure process

**Priority:** P2
**Labels:** docs, security
**Branch:** `docs/wmi-security-policy`
**Acceptance criteria:**
- `SECURITY.md` references Cacti Group security disclosure process
- Links to GitHub Security Advisories for private reporting
- No public CVE instructions for pre-auth findings

**Dependencies:** None
