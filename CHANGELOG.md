## ChangeLog

--- develop ---

* dev: Record which plugin lines the end-to-end run executes and report the changed lines no suite reaches (tests/bin/e2e-coverage-report.php)
* issue: Fix the breadcrumb parents of the Edit WMI Account and WMI Query Tool pages so the account edit page no longer fails on Cacti develop
* issue: Use the default of 10 concurrent WMI collectors when the setting is empty instead of waiting forever
* dev: Restore cacti.log after the end-to-end collector test so the CI log check stays clean
* dev: Add an end-to-end collector test that drives poller_wmi.php and the cache refresh through a fake wmic against the CI Cacti install and database
* issue: Fix the form action of the remove WMI Query confirmation on the Device Template page
* issue: Reject WMI account saves with a mismatched, missing or oversized password instead of saving a partial account
* issue: Count all matching rows for the WMI Queries and Accounts pagers
* security: Stop keeping the Query Tool password in the session and echoing it back into the form
* issue: Pass the default WMI namespace to wmic with a single backslash
* issue: Log the reason a WMI query failed during polling
* issue: Index cached WMI rows by every column of a comma separated primary key so multi-row results are no longer overwritten
* issue: Purge WMI cache rows the device stopped returning, and keep the existing rows when a query fails
* issue: Expire stale wmi_processes lock rows and stop the master poller waiting indefinitely for collectors that died
* security: Replace the confirmation pages' inline `onClick='cactiReturnTo()'` Cancel buttons with the CSP-safe `cactiReturnTo` class so the pages no longer trip Cacti's Content-Security-Policy script-src-attr directive
* security: Move the WMI Accounts and Queries filter rows selects' inline onChange handlers into the ready block so the pages no longer trip Cacti's Content-Security-Policy script-src-attr directive
* dev: Measure CI coverage with xdebug instead of pcov so the plugin's own sources are instrumented (pcov auto-scopes to the Composer root and skipped cacti/plugins/, leaving the patch-coverage gate with nothing to measure)
* dev: Enforce patch coverage of changed lines in CI and remove the inert COMPOSER_ROOT_VERSION env from the Pest step
* dev: Pin the CI database image and shared test dependencies, disable persisted checkout credentials, and limit workflow permissions and runtime
* security: Add a version-safe CSP nonce (`plugin_wmi_csp_nonce()`) to every inline `<script>` tag so pages stay compatible with Cacti's Content-Security-Policy nonce enforcement, while falling back cleanly on older Cacti releases that lack the `CactiSecureHeaders` class
* issue: PHPStan level 8 typing pass - fixed an incorrect script_path/script_function in the exported WMI query resource XML, restrictive drp_action/menu-index guards in wmi_accounts.php and wmi_queries.php, several unguarded array offset accesses on DB fetch results, and html_start_box() argument-type mismatches
* test: Expand Security/Unit/Integration Pest coverage for setup.php lifecycle, hook registration, and table/column provisioning
* chore: Harmonize CI workflow, issue/PR templates, and PHP-compatibility test structure with the shared Cacti plugin baseline
* feat: Add a PowerShell/CIM transport (PowerShellCim_Transport) for Windows collector hosts, with automatic transport selection based on the Cacti server OS
* security: Escape WMI account, query, and tool output on render (html_escape/__esc) to close stored and reflected XSS
* security: Bind the remaining interpolated SQL as prepared statements in functions.php, poller_wmi.php, and script/wmi-script.php
* security: Remove wmi_accounts.php and wmi_tools.php from the Template Editor auth augment so credential management and the live query tool stay behind the WMI Management realm
* security: Escape the wmic hostname and namespace before exec so a device-supplied address cannot inject a shell command (issue#5)
* security: Quote the wmic delimiter so exec() no longer splits the command into a shell pipeline (issue#5)
* security: Restrict decode() unserialize with allowed_classes to block PHP object injection (issue#5)
