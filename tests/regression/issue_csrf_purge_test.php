<?php

$setup = file_get_contents(dirname(__DIR__, 2) . '/setup.php');

if ($setup === false) {
	fwrite(STDERR, "Failed to load setup.php\n");
	exit(1);
}

if (strpos($setup, "if (\$action == 'purge_syslog_hosts') {") === false ||
    strpos($setup, "csrf_check") === false) {
	fwrite(STDERR, "CSRF check for purge action is missing in setup.php.\n");
	exit(1);
}

print "issue_csrf_purge_test passed\n";
