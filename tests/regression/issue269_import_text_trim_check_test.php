<?php

$root = dirname(__DIR__, 2);
$target = $root . '/functions.php';

$content = file_get_contents($target);

if ($content === false) {
	fwrite(STDERR, "Failed to load $target\n");
	exit(1);
}

$legacy = "trim(get_nfilter_request_var('import_text') != '')";

if (strpos($content, $legacy) !== false) {
	fwrite(STDERR, "Legacy import_text trim/comparison bug remains in $target\n");
	exit(1);
}

$fixedPattern = '/trim\s*\(\s*get_nfilter_request_var\s*\(\s*\'import_text\'\s*\)\s*\)\s*!=\s*\'\'/';
if (!preg_match($fixedPattern, $content)) {
	fwrite(STDERR, "Fixed import_text trim/comparison check missing in $target\n");
	exit(1);
}

print "issue269_import_text_trim_check_test passed\n";

