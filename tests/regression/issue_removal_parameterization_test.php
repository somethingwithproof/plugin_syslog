<?php

$functions = file_get_contents(dirname(__DIR__, 2) . '/functions.php');

if ($functions === false) {
	fwrite(STDERR, "Failed to load functions.php\n");
	exit(1);
}

if (strpos($functions, "WHERE facility ='\" . \$remove['message'].\"'\"") !== false) {
	fwrite(STDERR, "Legacy interpolated facility filter is still present in syslog_manage_items.\n");
	exit(1);
}

if (strpos($functions, "WHERE host ='\" . \$remove['message'].\"'\"") !== false) {
	fwrite(STDERR, "Legacy interpolated host filter is still present in syslog_manage_items.\n");
	exit(1);
}

if (strpos($functions, "WHERE facility = ?") === false ||
	strpos($functions, "WHERE host = ?") === false) {
	fwrite(STDERR, "Expected parameterized filters in syslog_manage_items are missing.\n");
	exit(1);
}

if (strpos($functions, "syslog_db_fetch_assoc_prepared(\$sql_sel, \$params)") === false) {
	fwrite(STDERR, "syslog_db_fetch_assoc_prepared with params is missing in syslog_manage_items.\n");
	exit(1);
}

if (strpos($functions, "syslog_db_execute_prepared(\$sql_dlt, \$params)") === false) {
	fwrite(STDERR, "syslog_db_execute_prepared with params is missing in syslog_manage_items.\n");
	exit(1);
}

print "issue_removal_parameterization_test passed\n";
