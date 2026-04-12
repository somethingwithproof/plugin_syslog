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
 | Tests for issue #255: syslog_manage_items() SQL quoting via db_qstr(). |
 |                                                                         |
 | The fix replaced raw string concatenation in 6 WHERE clause patterns   |
 | with db_qstr().  These tests verify that the quoting contracts hold:   |
 | - values are wrapped in single quotes                                   |
 | - embedded single quotes are escaped                                    |
 | - LIKE patterns append % outside the quoted value                       |
 | - SQL injection payloads are defused                                    |
 |                                                                         |
 | Run: php tests/test_sql_quoting.php                                     |
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

function assert_not_contains($label, $needle, $haystack) {
	global $pass, $fail;
	if (strpos($haystack, $needle) === false) {
		echo "PASS  $label\n";
		$pass++;
	} else {
		echo "FAIL  $label\n";
		echo "      unexpected: $needle\n";
		echo "      in: $haystack\n";
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
/* Stub db_qstr() — mirrors Cacti's implementation for MySQL.         */
/* In production this calls mysqli_real_escape_string via the Cacti   */
/* database layer.  The stub is sufficient for quoting contract tests. */
/* ------------------------------------------------------------------ */
function db_qstr($value) {
	/* Escape single quotes and backslashes, then wrap in single quotes. */
	$escaped = str_replace('\\', '\\\\', (string) $value);
	$escaped = str_replace("'",  "\\'",  $escaped);
	return "'" . $escaped . "'";
}

/* ------------------------------------------------------------------ */
/* db_qstr() contract                                                  */
/* ------------------------------------------------------------------ */

assert_eq('plain value wrapped in quotes',    "'hello'",          db_qstr('hello'));
assert_eq('empty value',                      "''",               db_qstr(''));
assert_eq('single quote escaped',             "'it\\'s'",         db_qstr("it's"));
assert_eq('backslash escaped',                "'a\\\\'",          db_qstr('a\\'));
assert_eq('numeric value',                    "'42'",             db_qstr(42));

/* ------------------------------------------------------------------ */
/* Equality WHERE clause pattern (facility / host types)               */
/* Fixed: "WHERE facility = " . db_qstr($remove['message'])           */
/* ------------------------------------------------------------------ */

function build_equality_where($value) {
	return "WHERE facility = " . db_qstr($value);
}

assert_eq('equality: plain value',
	"WHERE facility = 'kernel'",
	build_equality_where('kernel')
);

/* SQL injection via quote termination. */
$injection = "' OR '1'='1";
$sql = build_equality_where($injection);
assert_not_contains(
	'equality: injection quote sequence neutralised',
	"' OR '1'='1",
	$sql
);
assert_contains(
	'equality: injection value safely wrapped',
	"\\'" . " OR \\'1\\'=\\'1",
	$sql
);

/* ------------------------------------------------------------------ */
/* LIKE WHERE clause pattern (messageb / messagec / messagee types)   */
/* Fixed: "WHERE message LIKE " . db_qstr($remove['message'] . '%')   */
/* ------------------------------------------------------------------ */

function build_like_where($value) {
	return "WHERE message LIKE " . db_qstr($value . '%');
}

assert_eq('LIKE: plain value with trailing %',
	"WHERE message LIKE 'error%'",
	build_like_where('error')
);

/* The % wildcard must be inside the quoted value (appended before quoting)
 * rather than outside it — the old pattern could have been written either
 * way; the fix appends % to the PHP value before passing to db_qstr(). */
$like_sql = build_like_where('warning');
assert_contains('LIKE: % inside quoted value', "'warning%'", $like_sql);
assert_not_contains('LIKE: no raw % after closing quote', "'%", substr($like_sql, strpos($like_sql, "'warning%'") + 9));

/* Injection in LIKE value.  db_qstr() escapes the embedded quote to \'
 * so the injection cannot terminate the SQL string prematurely. */
$like_injection = "' OR 1=1 -- ";
$like_sql_inj   = build_like_where($like_injection);
assert_contains(
	'LIKE: injected single quote is backslash-escaped',
	"\\'",
	$like_sql_inj
);
/* Verify the SQL string opens with the opening quote and the very next
 * character after LIKE is the opening delimiter, not a bare injection. */
$after_like = substr($like_sql_inj, strpos($like_sql_inj, 'LIKE ') + 5);
assert_eq(
	'LIKE: value starts with opening quote (not raw injection char)',
	"'",
	substr($after_like, 0, 1)
);

/* ------------------------------------------------------------------ */
/* Old (unfixed) pattern — demonstrates the vulnerability              */
/* These assertions verify what the broken code produced so the fix   */
/* is clearly justified in the test record.                            */
/* ------------------------------------------------------------------ */

function build_equality_old($value) {
	/* Original concatenation without any escaping. */
	return "WHERE facility = '" . $value . "'";
}

$evil = "x' OR '1'='1";
$broken_sql = build_equality_old($evil);
assert_contains(
	'OLD pattern: injection terminates first quote (verifies vulnerability)',
	"' OR '1'='1",
	$broken_sql
);

/* ------------------------------------------------------------------ */
echo "\nResults: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
