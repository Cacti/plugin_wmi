# Cacti WMI Plugin for Windows Devices

The WMI Plugin queries Windows devices over WMI from the Cacti poller. It runs
asynchronously to the Cacti data collection and stores the results in cache
tables. It collects over WMI using one of two transports, chosen automatically
from the Cacti server's operating system (see Transports below).

### WARNING: Development in progress

This plugin is NOT fully functional.  It should not be used on production
systems.  Use at your own risk!

## Requirements

- PHP 8.0 or newer.  The code uses union types and constructor property
  promotion, so it parses on PHP 8.0 and does not parse on 7.4.  The CI matrix
  runs PHP 8.2, 8.3 and 8.4, and an earlier version of this file said 8.2; use
  one of those unless you have tested another.  Cacti's own requirement can be
  higher: 1.2.31 needs PHP 8.0, the current 1.2.x branch needs 8.2, and Cacti
  develop needs 8.3.
- Cacti 1.2.  Every Cacti core function the plugin calls exists in the 1.2.0
  release, except `auth_augment_roles()`, which the plugin calls only when it
  exists.  Installing, enabling, disabling and uninstalling the plugin, and
  opening its pages, were run on Cacti 1.2.20 (on PHP 8.1), 1.2.31, the 1.2.x
  branch (1.2.32) and Cacti develop (1.3.0).  Nothing was run on other
  releases.  CI runs against the 1.2.x branch.
- The MariaDB or MySQL server Cacti already uses.  One of the plugin's tables,
  `wmi_processes`, uses the MEMORY engine.
- For a Linux Cacti server, a `wmic` client at `/usr/bin/wmic` (see
  Transports).  For a Windows Cacti server, PowerShell.

## Known issues

- The breadcrumb of the Edit WMI Account page names a parent that does not
  exist.  On Cacti develop the page fails with a PHP error
  (`resolve_navigation_variables()` receives null); on the 1.2 releases it
  loads with a shortened title and logs warnings.

## Cacti compatibility

The Cancel buttons on the plugin's confirmation pages use the `cactiReturnTo`
class.  Cacti 1.2.31 and the current 1.2.x branch bind a click handler to that
class in `include/layout.js`.  Earlier releases, and Cacti develop, do not.  On
those, add the function below to the `applySkin()` function in
`include/layout.js` so the buttons work:

```js
$(document).off('click.cactiReturnTo', '.cactiReturnTo')
    .on('click.cactiReturnTo', '.cactiReturnTo', function(event) {
        event.preventDefault();
        cactiReturnTo($(this).attr('data-url'));
    });
```

## Installation

Copy the plugin directory into Cacti's `plugins` directory and name it `wmi`;
the plugin loads its files from `plugins/wmi`.  Then open Console >
Configuration > Plugins and Install and Enable the plugin, or run from the
Cacti directory:

```
php cli/plugin_manage.php --plugin=wmi --install --enable
```

The Cacti 1.2.20 release has no `cli/plugin_manage.php`; use the Plugins page
there.

Installing creates seven tables (see Tables), adds a `wmi_account` column to
Cacti's `host` table, registers the WMI Management realm and the plugin's
hooks, and adds two data input methods, `Get WMI Data` and `Get WMI Data
(Indexed)`.

Make certain that the `wmic` binary is installed if the Cacti server runs on
Linux.

## Upgrade

Replace the plugin files.  Cacti does not call the plugin's
`plugin_wmi_upgrade()` function (a search of Cacti 1.2.x and develop finds no
caller), so nothing runs on upgrade.  Files that a newer release no longer
ships (`functions.php` and `linux_wmi.php` in the plugin root, listed under
`tombstones` in `manifest.json`) are not removed; delete them by hand.

## Disable and uninstall

Disabling the plugin keeps its tables and data.  Uninstalling it drops the
seven tables, removes the `wmi_account` column from `host`, and removes the
plugin's hooks and its realm.  It also deletes the graphs and data sources
that use the two WMI data input methods.  The two data input methods
themselves remain in Cacti.  Installing again creates a new realm, so check
the permissions of users other than admin.

## Transports

Each query runs through a transport, chosen automatically from the Cacti
server's operating system.  Code that constructs `Linux_WMI` directly can pass
an explicit transport instead.

- **wmic** (default on a Linux server).  Uses the Linux `wmic` client to reach
  Windows hosts over DCOM.  The client does not appear in the Debian 12 or Ubuntu
  18.04 package lists, so install it from your own package source or build
  it.  The Query Tool and the poller take the binary from the `path_wmi`
  setting and use `/usr/bin/wmic` when it is empty; `path_wmi` has no field on
  the Settings page yet.  `wmi_script.php` always uses `/usr/bin/wmic`, and `script/wmi-script.php`
  looks for a `wmic` inside the plugin directory, which is not shipped.  The
  user name and password are passed to `wmic` as `--user` and `--password`
  arguments, so a local user can read them in the process list while a query
  runs.  This client predates current Windows hardening.  Hosts that disable
  NTLMv1 or enforce the DCOM packet-integrity level (KB5004442, enforced
  without an opt-out since March 2023) may reject it.  There is currently no
  console setting to switch a Linux Cacti server to the PowerShell transport;
  it is only available to code that constructs `Linux_WMI` directly and
  passes an explicit transport.
- **PowerShell / CIM** (default on a Windows server).  Runs `powershell.exe`
  and uses `Get-CimInstance`, so it works when Cacti runs on Windows and
  against a remote host over WinRM/CIM.  Needs PowerShell on the Cacti server
  and, for a remote host, WinRM enabled on the target.  Credentials and every
  device-supplied value are passed through the child process environment and a
  fixed script on standard input, never on the command line.

## Usage

The WMI plugin allows you to define multiple Queries, and then associate those
queries with Cacti Device Templates.  Therefore, if you have a generic Windows
Device Template, you can add those queries that are significant for a general
Windows device.  If you have a Microsoft SQL Server Device Template, you can add
WMI Queries that are specific to Windows SQL Server there.

- Console > Data Collection > WMI Queries lists and edits the queries.  The
  Authentication tab on the same page holds the Windows accounts (user name and
  password) that devices use.
- Console > Utilities > WMI Query Tool runs an ad-hoc query against a host for
  testing.
- The Device Template page lists the queries associated with the template
  (Associated WMI Queries), with a delete link and an Add button.
- The Device page has a WMI Authentication Account field.  A device is only
  polled when an account is selected.  The page also shows which of the
  template's queries exist for the device.

## Access

The plugin registers a WMI Management realm covering account management, query
definition, and the query tool.  The Template Editor role is additionally
granted the query-definition page only; credential management and the live query
tool stay behind the WMI Management realm.  The poller needs the host password
in cleartext, so accounts are stored reversibly (base64 of a serialized array),
not hashed or encrypted; protect the database accordingly.

## Settings

The plugin adds three settings to the Misc tab of Settings:

| Setting | Name | Default | Effect |
|---------|------|---------|--------|
| Enable WMI Data Collection | `wmi_enabled` | on | Not read by any code yet. |
| Concurrent Processes | `wmi_processes` | 10 | Most collector processes that run at once (1 to 10, then steps of 5 up to 50). |
| Auto Create WMI Queries | `wmi_autocreate` | on | Read by a hook that does nothing yet, so queries are not created automatically. |

The poller also reads `wmi_autopurge` (when `on`, it deletes cache rows of
devices that no longer exist), and the Query Tool and poller read `path_wmi`.
Neither has a field on the Settings page.

## Data collection

At the end of each polling cycle on the main poller, the `poller_bottom` hook
starts `poller_wmi.php -M` in the background.  The master selects devices that
are not down and not disabled, have an account, have a template query that is
enabled and due (its frequency has passed since it last started), and starts one
`poller_wmi.php --host-id=N` child per device, at most `wmi_processes` at a
time.  Children run the due queries and store the rows in `host_wmi_cache`.  A
summary goes to the `stats_wmi` setting and the Cacti log (`WMI STATS`).

Running collectors are tracked in the `wmi_processes` table.  The master exits
with `Another WMI Collector is still running` while that table has any row.  A
row left behind by a collector that was killed never expires; delete the rows
by hand when no collector is running:

```
DELETE FROM wmi_processes;
```

`poller_wmi.php` options:

| Option | Meaning |
|--------|---------|
| `-M` | Master run: poll every device that is due. |
| `--host-id=N` | Child run for one device (required without `-M`). |
| `--seed=N`, `--key=N`, `-s=N` or `--start=N` | Set by the master for its children.  Write the value after an `=`. |
| `-f`, `--force` | Accepted and passed on to children; it does not change which devices are polled. |
| `-d`, `--debug` | Accepted and passed on to children; no message uses it yet. |
| `-V`, `-v`, `--version` | Print the version. |
| `-H`, `-h`, `--help` | Print usage.  It lists only `-M`, `--host-id`, `--seed`, `-f` and `-d`. |

## Tables

| Table | Holds |
|-------|-------|
| `wmi_user_accounts` | Accounts: name, user name, password. |
| `wmi_wql_queries` | Queries: name, frequency, enabled, namespace, WQL text, primary key. |
| `host_template_wmi_query` | Queries associated with a Device Template. |
| `host_wmi_query` | When each query last started, how long it ran and when it last failed, per device. |
| `host_wmi_cache` | The collected rows. |
| `wmi_processes` | Collectors that are running (MEMORY engine). |
| `host_wmi_accounts` | Created but not used by any code. |

## Script server template

`templates/resource/script_server/exchange_messages.xml` is a sample Script
Server query for Exchange mail stores.  Nothing in the plugin copies or
imports it.  Its `arg_prepend` names a WMI query called `exchangemessages`, so
a query with that name must exist.  Its `script_path` is
`|path_cacti|/scripts/wmi-script.php`, which does not exist; the plugin ships
`script/wmi-script.php`.  The two functions that generate such XML from a saved
query, `plugin_wmi_create_dataquery_xml()` and
`plugin_wmi_create_resource_xml()`, are not called from anywhere.

## Translations

`locales/po` holds the translation files and `locales/LC_MESSAGES` the
compiled `.mo` files.  `locales/build_gettext.sh` rebuilds the template
`locales/po/cacti.pot`, merges it into the `.po` files and compiles the `.mo`
files.  It needs GNU gettext, `realpath` and GNU `sed`.  It was written for
Cacti core, so in a plugin it prints `cat: include/cacti_version: No such file
or directory` and leaves the package version in the template empty.

## Development

CI (`.github/workflows/plugin-ci-workflow.yml`) installs Cacti from the 1.2.x
branch, installs and enables the plugin, then runs these checks from the
plugin directory:

```
php tests/bin/validate-manifest.php
php tests/bin/check-i18n-pot.php <base-ref>
```

`validate-manifest.php` fails when the top-level entries of the plugin differ
from the `expected` list in `manifest.json`, in either direction, so run it
before the tests create a `coverage` directory in the plugin.
`check-i18n-pot.php` fails when a change adds or removes a translatable string
without updating `locales/po/cacti.pot`.

The tests run with Pest from the Cacti directory, with `tests/.cacti-version`
set to `1.2.x` or `develop`:

```
<pest> --configuration=plugins/wmi/phpunit.xml --coverage-clover=plugins/wmi/coverage/clover.xml
```

`<pest>` is the Pest binary of Cacti's `tests/tools/plugin-harness` for Cacti
1.2.x, or `include/vendor/bin/pest` in a Cacti develop checkout installed with
its development dependencies.  The patch coverage check then compares against
Git, so run it from the plugin directory; it fails when the lines a change adds
are not all covered:

```
php tests/bin/patch-coverage.php coverage/clover.xml <base-ref> 100
```

## Authors

The wmi plugin was developed several years ago but never finished.

## ChangeLog

See `CHANGELOG.md`.

-----------------------------------------------
Copyright (c) 2004-2026 - The Cacti Group, Inc.
