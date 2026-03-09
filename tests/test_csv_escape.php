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
 | Standalone tests for syslog_csv_escape().                               |
 | Run: php tests/test_csv_escape.php                                      |
 +-------------------------------------------------------------------------+
*/

/* Extract just the function without pulling in the full plugin. */
function syslog_csv_escape($value) {
	$value = str_replace('"', '""', (string) $value);
	if (strlen($value) > 0 && strpos("=+-@\t\r", $value[0]) !== false) {
		$value = "'" . $value;
	}
	return $value;
}

/* ------------------------------------------------------------------ */
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

/* ------------------------------------------------------------------ */
/* Formula-injection prefix escaping                                   */
/* ------------------------------------------------------------------ */

assert_eq('= prefix prefixed',       "'=SUM(A1)",    syslog_csv_escape('=SUM(A1)'));
assert_eq('+ prefix prefixed',       "'+1+1",        syslog_csv_escape('+1+1'));
assert_eq('- prefix prefixed',       "'-1",          syslog_csv_escape('-1'));
assert_eq('@ prefix prefixed',       "'@formula",    syslog_csv_escape('@formula'));
assert_eq('tab prefix prefixed',     "'" . "\t" . "data", syslog_csv_escape("\tdata"));
assert_eq('CR prefix prefixed',      "'" . "\r" . "data", syslog_csv_escape("\rdata"));

/* Safe characters must NOT be prefixed. */
assert_eq('plain word unchanged',    'hello',        syslog_csv_escape('hello'));
assert_eq('number unchanged',        '42',           syslog_csv_escape(42));
assert_eq('empty string unchanged',  '',             syslog_csv_escape(''));
assert_eq('IP address unchanged',    '192.168.1.1',  syslog_csv_escape('192.168.1.1'));

/* Formula character in the middle — must NOT trigger prefix. */
assert_eq('= in middle unchanged',   'a=b',          syslog_csv_escape('a=b'));

/* ------------------------------------------------------------------ */
/* Double-quote escaping                                               */
/* ------------------------------------------------------------------ */

assert_eq('double-quote doubled',         '""',            syslog_csv_escape('"'));
assert_eq('embedded double-quote doubled','say ""hello""', syslog_csv_escape('say "hello"'));
assert_eq('multiple quotes doubled',      '""""""',        syslog_csv_escape('"""'));

/* Formula prefix + embedded quote: prefix applied after quote doubling. */
assert_eq('= with embedded quote',
	"'=" . '""say""',
	syslog_csv_escape('="say"')
);

/* ------------------------------------------------------------------ */
/* Type coercion                                                        */
/* ------------------------------------------------------------------ */

assert_eq('null coerced to empty string', '', syslog_csv_escape(null));
assert_eq('false coerced to empty string', '', syslog_csv_escape(false));
assert_eq('integer 0',                    '0', syslog_csv_escape(0));
assert_eq('float',                        '3.14', syslog_csv_escape(3.14));

/* ------------------------------------------------------------------ */
echo "\nResults: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
