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
 | Tests for the XML import 1 MB size guard (issue #262).                 |
 |                                                                         |
 | The guard in syslog_alerts.php / syslog_reports.php / syslog_removal.php
 | is:                                                                     |
 |   if (filesize($tmp) > 1048576) { reject }                             |
 |                                                                         |
 | These tests exercise the boundary logic using real temporary files so   |
 | filesize() returns accurate values.                                     |
 |                                                                         |
 | Run: php tests/test_import_size_guard.php                              |
 +-------------------------------------------------------------------------+
*/

const SYSLOG_IMPORT_MAX = 1048576; /* 1 MiB */

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

/* Helper: create a tmp file of exactly $size bytes, return its path. */
function make_tmp_file($size) {
	$path = tempnam(sys_get_temp_dir(), 'syslog_test_');
	file_put_contents($path, str_repeat('x', $size));
	return $path;
}

/* Helper: mirrors the guard expression used in the three import pages. */
function import_would_be_rejected($tmp_path) {
	return filesize($tmp_path) > SYSLOG_IMPORT_MAX;
}

/* ------------------------------------------------------------------ */
/* Boundary tests                                                       */
/* ------------------------------------------------------------------ */

$empty = make_tmp_file(0);
assert_eq('0-byte file: accepted',           false, import_would_be_rejected($empty));
unlink($empty);

$small = make_tmp_file(1024);
assert_eq('1 KB file: accepted',             false, import_would_be_rejected($small));
unlink($small);

$at_limit = make_tmp_file(SYSLOG_IMPORT_MAX);
assert_eq('exactly 1 MiB file: accepted',   false, import_would_be_rejected($at_limit));
unlink($at_limit);

$one_over = make_tmp_file(SYSLOG_IMPORT_MAX + 1);
assert_eq('1 MiB + 1 byte: rejected',       true,  import_would_be_rejected($one_over));
unlink($one_over);

$large = make_tmp_file(SYSLOG_IMPORT_MAX * 2);
assert_eq('2 MiB file: rejected',           true,  import_would_be_rejected($large));
unlink($large);

/* ------------------------------------------------------------------ */
/* Constant value verification                                          */
/* ------------------------------------------------------------------ */

assert_eq('limit constant is exactly 1 MiB (1048576)', 1048576, SYSLOG_IMPORT_MAX);

/* ------------------------------------------------------------------ */
/* file_get_contents reads full content when within limit              */
/* ------------------------------------------------------------------ */

$content = '<syslog_export><rule id="1"/></syslog_export>';
$valid   = make_tmp_file(0);
file_put_contents($valid, $content);
$read = file_get_contents($valid);
assert_eq('file_get_contents returns full content for valid file',
	$content, $read);
unlink($valid);

/* ------------------------------------------------------------------ */
echo "\nResults: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
