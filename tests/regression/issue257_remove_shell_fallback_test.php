<?php

$functions = file_get_contents(dirname(__DIR__, 2) . '/functions.php');

if ($functions === false) {
	fwrite(STDERR, "Failed to load functions.php\n");
	exit(1);
}

if (strpos($functions, "exec('/bin/sh ' . \$command") !== false) {
	fwrite(STDERR, "Shell fallback execution path is still present.\n");
	exit(1);
}

if (preg_match_all('/\bpreg_split\b/', $functions) < 2) {
	fwrite(STDERR, "Expected command tokenization update is missing.\n");
	exit(1);
}

if (preg_match_all('/\$returnCode\s*=\s*126/', $functions) < 2) {
	fwrite(STDERR, "Expected non-executable return code handling is missing.\n");
	exit(1);
}

if (preg_match_all('/SYSLOG ERROR: Alert command is not executable/', $functions) < 2) {
	fwrite(STDERR, "Expected non-executable command log message is missing.\n");
	exit(1);
}

echo "issue257_remove_shell_fallback_test passed\n";
