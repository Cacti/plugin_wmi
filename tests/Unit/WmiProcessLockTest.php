<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The master poller counts wmi_processes rows to decide whether a collector
 * is still running. A collector that dies leaves its row behind, so stale
 * rows must expire and the master's wait for its children must end.
 */

require_once __DIR__ . '/../../includes/functions.php';

beforeEach(function () {
	$GLOBALS['__test_db_calls']       = array();
	$GLOBALS['__test_cacti_log']      = array();
	$GLOBALS['__test_fetch_cell_map'] = array();
});

it('deletes lock rows older than the maximum run time', function () {
	wmi_purge_stale_processes(900);

	$call = $GLOBALS['__test_db_calls'][0];

	expect($call['fn'])->toBe('db_execute_prepared');
	expect($call['sql'])->toContain('DELETE FROM wmi_processes WHERE started <');
	expect($call['params'])->toBe(array(900));
});

it('reports the running collectors for a seed while inside the deadline', function () {
	$GLOBALS['__test_fetch_cell_map']['FROM wmi_processes WHERE taskid'] = '2';

	expect(wmi_collectors_running(77, time() - 10, 3600))->toBe(2);
	expect($GLOBALS['__test_db_calls'])->toBe(array());
});

it('stops waiting and releases the seed rows once the deadline passes', function () {
	$GLOBALS['__test_fetch_cell_map']['FROM wmi_processes WHERE taskid'] = '2';

	expect(wmi_collectors_running(77, time() - 7200, 3600))->toBe(0);

	$call = $GLOBALS['__test_db_calls'][0];

	expect($call['sql'])->toContain('DELETE FROM wmi_processes WHERE taskid = ?');
	expect($call['params'])->toBe(array(77));
	expect($GLOBALS['__test_cacti_log'][0])->toContain('WMI collectors');
});

it('expires stale locks before the master checks for a running collector', function () {
	$source = plugin_test_read_source('poller_wmi.php');

	expect(strpos($source, 'wmi_purge_stale_processes()'))->toBeLessThan(strpos($source, "SELECT COUNT(*) FROM wmi_processes') > 0"));
	expect($source)->toContain('wmi_collectors_running($seed, $wait_began)');
});
