## ChangeLog

--- develop ---

* security: Escape WMI account, query, and tool output on render (html_escape/__esc) to close stored and reflected XSS
* security: Bind the remaining interpolated SQL as prepared statements in functions.php, poller_wmi.php, and script/wmi-script.php
* security: Remove wmi_accounts.php and wmi_tools.php from the Template Editor auth augment so credential management and the live query tool stay behind the WMI Management realm
* security: Escape the wmic hostname and namespace before exec so a device-supplied address cannot inject a shell command (issue#5)
* security: Quote the wmic delimiter so exec() no longer splits the command into a shell pipeline (issue#5)
* security: Restrict decode() unserialize with allowed_classes to block PHP object injection (issue#5)
