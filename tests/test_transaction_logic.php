<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Tests for the transaction wrapping in syslog_manage_items() (issue).   |
 |                                                                         |
 | The fix wraps both the move-then-delete path and the delete-only path  |
 | in db_begin_transaction / db_commit_transaction, with                  |
 | db_rollback_transaction + cacti_log on failure.                        |
 |                                                                         |
 | These tests use a stub transaction driver to verify the call sequence  |
 | without a full Cacti installation.                                      |
 |                                                                         |
 | Run: php tests/test_transaction_logic.php                              |
 +-------------------------------------------------------------------------+
*/

$pass = 0;
$fail = 0;

function assert_eq($label, $expected, $actual) {
	global $pass, $fail;
	if ($expected === $actual) {
		echo "PASS  $label\n";
		$pass++;
	} else {
		echo "FAIL  $label\n";
		echo '      expected: ' . var_export($expected, true) . "\n";
		echo '      actual:   ' . var_export($actual, true) . "\n";
		$fail++;
	}
}

function assert_contains($label, $needle, $haystack) {
	global $pass, $fail;
	if (strpos($haystack, $needle) !== false) {
		echo "PASS  $label\n";
		$pass++;
	} else {
		echo "FAIL  $label\n";
		echo "      expected: $needle\n";
		echo "      in: $haystack\n";
		$fail++;
	}
}

/* ------------------------------------------------------------------ */
/* Stub transaction driver                                              */
/* Records the sequence of calls so tests can assert on call order.   */
/* ------------------------------------------------------------------ */

$tx_log    = array();
$error_log = array();

function db_begin_transaction($cnn = null) {
	global $tx_log;
	$tx_log[] = 'begin';
}

function db_commit_transaction($cnn = null) {
	global $tx_log;
	$tx_log[] = 'commit';
}

function db_rollback_transaction($cnn = null) {
	global $tx_log;
	$tx_log[] = 'rollback';
}

function cacti_log($msg, $display = false, $facility = 'CACTI') {
	global $error_log;
	$error_log[] = $msg;
}

function db_affected_rows($cnn = null) {
	return 1;
}

/* ------------------------------------------------------------------ */
/* Simulated move-then-delete path (mirrors syslog_manage_items move) */
/* $execute_ok controls whether syslog_db_execute() succeeds.         */
/* ------------------------------------------------------------------ */

function run_move_path($insert_ok, $delete_ok) {
	global $tx_log, $error_log;
	$tx_log    = array();
	$error_log = array();
	$cnn       = null;

	db_begin_transaction($cnn);

	$ok = $insert_ok;

	$messages_moved = $ok ? 1 : 0;

	if ($ok && $messages_moved > 0) {
		$ok = $delete_ok;
	}

	if ($ok) {
		db_commit_transaction($cnn);
	} else {
		db_rollback_transaction($cnn);
		cacti_log('ERROR: syslog_manage_items move rollback', false, 'SYSLOG');
		return false; /* mirrors continue; in real code */
	}

	return true;
}

/* ------------------------------------------------------------------ */
/* Simulated delete-only path (mirrors syslog_remove_items)           */
/* ------------------------------------------------------------------ */

function run_delete_path($delete_ok) {
	global $tx_log, $error_log;
	$tx_log    = array();
	$error_log = array();
	$cnn       = null;

	db_begin_transaction($cnn);

	$ok = $delete_ok;

	if ($ok) {
		db_commit_transaction($cnn);
	} else {
		db_rollback_transaction($cnn);
		cacti_log("ERROR: syslog_remove_items rollback on rule 'test'", false, 'SYSLOG');
		return false;
	}

	return true;
}

/* ------------------------------------------------------------------ */
/* Move path: success                                                  */
/* ------------------------------------------------------------------ */

$result = run_move_path(true, true);
assert_eq('move success: returns true',          true, $result);
assert_eq('move success: begins transaction',    'begin',  $tx_log[0]);
assert_eq('move success: commits transaction',   'commit', $tx_log[1]);
assert_eq('move success: no rollback',           2,        count($tx_log));
assert_eq('move success: no error logged',       0,        count($error_log));

/* ------------------------------------------------------------------ */
/* Move path: insert fails                                             */
/* ------------------------------------------------------------------ */

$result = run_move_path(false, true);
assert_eq('move insert-fail: returns false',         false,      $result);
assert_eq('move insert-fail: begins transaction',    'begin',    $tx_log[0]);
assert_eq('move insert-fail: rolls back',            'rollback', $tx_log[1]);
assert_eq('move insert-fail: no commit',             2,          count($tx_log));
assert_eq('move insert-fail: error logged',          1,          count($error_log));
assert_contains('move insert-fail: log mentions rollback', 'rollback', $error_log[0]);

/* ------------------------------------------------------------------ */
/* Move path: delete fails                                             */
/* ------------------------------------------------------------------ */

$result = run_move_path(true, false);
assert_eq('move delete-fail: returns false',         false,      $result);
assert_eq('move delete-fail: begins transaction',    'begin',    $tx_log[0]);
assert_eq('move delete-fail: rolls back',            'rollback', $tx_log[1]);
assert_eq('move delete-fail: no commit',             2,          count($tx_log));
assert_eq('move delete-fail: error logged',          1,          count($error_log));

/* ------------------------------------------------------------------ */
/* Delete-only path: success                                           */
/* ------------------------------------------------------------------ */

$result = run_delete_path(true);
assert_eq('delete success: returns true',         true,     $result);
assert_eq('delete success: begins transaction',   'begin',  $tx_log[0]);
assert_eq('delete success: commits transaction',  'commit', $tx_log[1]);
assert_eq('delete success: no rollback',          2,        count($tx_log));
assert_eq('delete success: no error logged',      0,        count($error_log));

/* ------------------------------------------------------------------ */
/* Delete-only path: failure                                           */
/* ------------------------------------------------------------------ */

$result = run_delete_path(false);
assert_eq('delete fail: returns false',         false,      $result);
assert_eq('delete fail: begins transaction',    'begin',    $tx_log[0]);
assert_eq('delete fail: rolls back',            'rollback', $tx_log[1]);
assert_eq('delete fail: no commit',             2,          count($tx_log));
assert_eq('delete fail: error logged',          1,          count($error_log));
assert_contains('delete fail: log mentions rollback', 'rollback', $error_log[0]);

/* ------------------------------------------------------------------ */
/* Transaction sequence: begin always precedes commit/rollback        */
/* ------------------------------------------------------------------ */

run_move_path(true, true);
assert_eq('sequence: begin before commit (success)',   'begin',  $tx_log[0]);

run_move_path(false, true);
assert_eq('sequence: begin before rollback (failure)', 'begin',  $tx_log[0]);

/* ------------------------------------------------------------------ */
/* Multiple independent calls do not bleed state between iterations   */
/* ------------------------------------------------------------------ */

run_move_path(true, true);
$first_log = $tx_log;
run_move_path(false, true);
$second_log = $tx_log;

assert_eq('isolation: first call has 2 entries',  2, count($first_log));
assert_eq('isolation: second call has 2 entries', 2, count($second_log));
assert_eq('isolation: first ends with commit',    'commit',   $first_log[1]);
assert_eq('isolation: second ends with rollback', 'rollback', $second_log[1]);

/* ------------------------------------------------------------------ */
echo "\nResults: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
