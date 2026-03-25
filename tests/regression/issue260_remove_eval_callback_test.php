<?php

$javascript = file_get_contents(dirname(__DIR__, 2) . '/js/functions.js');

if ($javascript === false) {
	fwrite(STDERR, "Failed to load js/functions.js\n");
	exit(1);
}

if (strpos($javascript, 'eval(') !== false) {
	fwrite(STDERR, "Unsafe eval() callback execution is still present.\n");
	exit(1);
}

if (!preg_match('/function\s+runSyslogAutocompleteOnChange\s*\(\s*onChange\s*\)/', $javascript)) {
	fwrite(STDERR, "Safe autocomplete callback helper is missing.\n");
	exit(1);
}

if (strpos($javascript, "if (!callbackName.match(/^[A-Za-z_$][A-Za-z0-9_$]*$/))") === false) {
	fwrite(STDERR, "Autocomplete callback name validation is missing.\n");
	exit(1);
}

$hasCallbackTypeCheck = preg_match(
	'/if\s*\(\s*typeof\s+window\s*\[\s*callbackName\s*\]\s*===\s*[\'"]{1}function[\'"]{1}\s*\)/',
	$javascript
) === 1;

$hasCallbackInvocation = preg_match(
	'/window\s*\[\s*callbackName\s*\]\s*\(/',
	$javascript
) === 1;

if (!$hasCallbackTypeCheck || !$hasCallbackInvocation) {
	fwrite(STDERR, "Expected function-reference callback execution is missing.\n");
	exit(1);
}

if (strpos($javascript, 'runSyslogAutocompleteOnChange(onChange);') === false) {
	fwrite(STDERR, "Autocomplete select handler is not using safe callback execution.\n");
	exit(1);
}

print "issue260_remove_eval_callback_test passed\n";
