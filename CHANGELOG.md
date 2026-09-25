## ChangeLog

--- develop ---

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
