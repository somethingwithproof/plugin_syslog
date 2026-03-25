<?php

$functions = file_get_contents(dirname(__DIR__, 2) . '/functions.php');

if ($functions === false) {
	fwrite(STDERR, "Failed to load functions.php\n");
	exit(1);
}

if (strpos($functions, "WHERE host LIKE '%\$domain'") !== false) {
	fwrite(STDERR, "Legacy interpolated domain LIKE clause is still present.\n");
	exit(1);
}

if (strpos($functions, 'AND `status` = $uniqueID') !== false) {
	fwrite(STDERR, "Legacy interpolated status filter is still present.\n");
	exit(1);
}

if (strpos($functions, "WHERE host LIKE ?") === false ||
	strpos($functions, "AND `status` = ?") === false ||
	strpos($functions, "array('%' . \$domain, \$uniqueID)") === false) {
	fwrite(STDERR, "Expected parameterized domain strip query is missing.\n");
	exit(1);
}

print "issue261_domain_strip_parameterized_test passed\n";
