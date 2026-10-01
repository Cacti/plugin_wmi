<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Including includes/functions.php must pull in the Linux_WMI client classes
 * that run_store_wmi_query() constructs.
 */

require_once __DIR__ . '/../../includes/functions.php';

it('loads the Linux_WMI client when functions.php is included', function () {
	expect(class_exists('Linux_WMI'))->toBeTrue();
});
