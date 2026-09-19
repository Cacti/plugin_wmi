# GitHub Copilot Instructions

## Priority Guidelines

When generating code for this repository:

1. **Version Compatibility**: This is a Cacti plugin (`wmi`, version 1.0) targeting Cacti 1.1.4+ compatibility metadata. **This plugin is explicitly marked "Development in progress" / NOT fully functional in its own README — treat existing code as a work in progress, not a stable reference.**
2. **Context Files**: Prioritize patterns and standards defined in this file (`.github/copilot-instructions.md`)
3. **Codebase Patterns**: When context files don't provide specific guidance, scan the codebase for established patterns
4. **Architectural Consistency**: Maintain plugin-based architecture extending Cacti core
5. **Code Quality**: Prioritize security, maintainability, and compatibility in all generated code

## Technology Stack

### Core Technologies
- **PHP**: Compatible with Cacti 1.2.x supported versions
- **Platform**: Cacti Plugin Architecture ("WMI Information Collector")
- **Database**: MySQL/MariaDB
- **WMI**: Uses the `wmic` command on Linux collectors, or native WMI/COM on Windows Cacti servers

### Key Dependencies
- Cacti core framework (`api_plugin_*`, `db_*`, `api_plugin_db_add_column()`)
- `script/` external collection scripts, `tests/` test suite

## Project Structure

```
wmi/                     # Repository root (install to plugins/wmi/ in Cacti)
├── script/                # External wmic/collection helper scripts
├── templates/                # Data query/graph template XML
├── tests/                      # Test suite
├── functions.php                 # Core hook callbacks and shared logic
├── linux_wmi.php                   # Linux (`wmic`) collection path
├── poller_wmi.php                    # Background poller entry point (CLI)
├── wmi_accounts.php                    # WMI credential administration
├── wmi_queries.php                       # WQL query administration
├── wmi_script.php                          # Ad hoc query/tool runner
├── wmi_tools.php                             # Diagnostic tools UI
├── INFO                                        # Plugin metadata (name, version, compat)
├── README.md
└── setup.php                                    # Plugin install/uninstall/upgrade hooks
```

## Naming Conventions

### Function Names
- **Plugin lifecycle/hook-registration functions** MUST be prefixed `plugin_wmi_`: `plugin_wmi_install()`, `plugin_wmi_uninstall()`, `plugin_wmi_version()`.
- **All other functions** MUST be prefixed `wmi_`: `wmi_poller_bottom()`, `wmi_config_arrays()`, `wmi_api_device_save()`.
- Match the existing prefix used by the function you are editing; do not introduce a third naming scheme.

### Database Tables
Tables use **unprefixed, descriptive names** (not `plugin_wmi_`): `wmi_processes`, `wmi_user_accounts`, `wmi_wql_queries`, `host_template_wmi_query`, `host_wmi_accounts`, `host_wmi_query`, `host_wmi_cache`. Preserve this naming; several tables intentionally extend the core `host*` naming family since they attach WMI config directly to Cacti hosts/host templates.

## Code Style

### Indentation and Formatting
- **Tabs**: Use tabs (not spaces) for indentation throughout all PHP files.
- **Braces**: Opening brace on the same line for functions and control structures.
- **Spacing**: Space after control structure keywords (`if`, `foreach`, `while`).

### File Headers
ALL PHP files MUST include the standard GPL v2 license header used throughout this repository (see `setup.php`), crediting "The Cacti Group".

## Security Standards

### Credential Handling
WMI accounts (`wmi_accounts.php`) store credentials used to authenticate to remote Windows hosts. Never log credential values, and ensure any shell invocation of `wmic` passes the username/password via `cacti_escapeshellarg()`, never raw string concatenation.

### SQL Query Security
Use prepared statements for anything involving variable input:

```php
// CORRECT
db_fetch_assoc_prepared('SELECT * FROM host_wmi_query WHERE host_id = ?', array($host_id));

// WRONG - never do this with request-derived values
db_fetch_row("SELECT * FROM wmi_user_accounts WHERE host_id = $host_id");
```

### Input Validation
Use `get_filter_request_var()` / `get_nfilter_request_var()` for request input; never read `$_GET`/`$_POST` directly.

`get_filter_request_var()` (and its `gfrv()` shorthand, where available) called with only the
`$name` argument (no regex/filter as the 2nd/3rd argument) already validates the value as numeric
and returns it as a **string** -- it does not return an int, and it halts execution if the request
value is not numeric. Because of this, do NOT cast its output to `(int)` when the result is only
used for string output (e.g. `print`/`echo`, string concatenation, embedding in HTML/JS); the cast
is redundant. Only cast when the value is genuinely used in an integer/numeric context (e.g.
arithmetic, strict `===` comparisons).

## Database Operations

### Column Additions
Use `api_plugin_db_add_column()` for adding columns to core tables like `host` (see `plugin_wmi_setup_tables()`), rather than raw `ALTER TABLE`.

## Internationalization

ALL user-facing strings MUST use `__()` with the `'wmi'` text domain.

## Plugin Architecture

### Plugin Hooks
Register all plugin hooks in `plugin_wmi_install()` (`setup.php`):

```php
api_plugin_register_hook('wmi', 'config_arrays',        'wmi_config_arrays',        'setup.php');
api_plugin_register_hook('wmi', 'config_form',          'wmi_config_form',          'setup.php');
api_plugin_register_hook('wmi', 'config_settings',      'wmi_config_settings',      'setup.php');
api_plugin_register_hook('wmi', 'draw_navigation_text', 'wmi_draw_navigation_text', 'setup.php');
api_plugin_register_hook('wmi', 'api_device_save',      'wmi_api_device_save',      'setup.php');
api_plugin_register_hook('wmi', 'data_input_sql_where', 'wmi_data_input_sql_where', 'setup.php');
api_plugin_register_hook('wmi', 'poller_bottom',        'wmi_poller_bottom',        'setup.php');
api_plugin_register_hook('wmi', 'device_template_edit',   'wmi_device_template_edit',   'setup.php');
api_plugin_register_hook('wmi', 'device_template_top',    'wmi_device_template_top',    'setup.php');
api_plugin_register_hook('wmi', 'device_edit_pre_bottom', 'wmi_device_edit_pre_bottom', 'setup.php');
api_plugin_register_hook('wmi', 'api_device_new',         'wmi_api_device_new',         'setup.php');

api_plugin_register_realm('wmi', 'wmi_accounts.php,wmi_queries.php,wmi_tools.php', __('WMI Management', 'wmi'), 1);
```

### Collection Path
Collection is asynchronous relative to normal Cacti polling: results land in `host_wmi_cache`, which graphs/thresholds then read from — do not make graphing code call `wmic` synchronously on the request thread.

## Best Practices

1. Treat this plugin as pre-production; validate changes carefully and call out any known-incomplete behavior in PRs.
2. Never expose WMI credentials in logs, error messages, or client-side output.
3. Preserve the existing `host_wmi_*`/`wmi_*` table naming.
4. Wrap all user-facing strings with `__('text', 'wmi')`.

## Common Pitfalls to Avoid

```php
// WRONG - building a wmic shell command from unescaped input
exec("wmic -U $user%$pass //$host \"$query\"");

// CORRECT
exec('wmic -U ' . cacti_escapeshellarg($user . '%' . $pass) . ' //' . cacti_escapeshellarg($host) . ' ' . cacti_escapeshellarg($query));
```

## Version Control

Document all changes in `CHANGELOG.md`; use descriptive commit messages referencing issue/PR numbers when applicable.

## References

- [Cacti main repo](https://github.com/Cacti/cacti/tree/1.2.x)
- [Cacti Documentation](https://www.github.com/Cacti/documentation)
- `README.md` for feature descriptions
- `CHANGELOG.md` for version history
