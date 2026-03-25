<?php

/*
 * Regression test for issue #269 -- branch-logic invariants.
 *
 * These assertions verify the structural properties that make whitespace-only
 * input fall through to the file-upload branch instead of the textbox branch,
 * and that a non-empty payload is assigned to $xml_data without further
 * modification.  Pure source inspection: the functions themselves cannot be
 * called in isolation because they depend on the Cacti runtime.
 */

$root = dirname(__DIR__, 2);
$target = $root . '/functions.php';

$content = file_get_contents($target);

if ($content === false) {
	fwrite(STDERR, "Failed to load $target\n");
	exit(1);
}

/*
 * 1. The branch condition must trim the request call.  This is what makes
 *    whitespace-only values fall through to the file-upload branch.
 */
if (!preg_match('/trim\s*\(\s*get_nfilter_request_var\s*\(\s*\'import_text\'\s*\)\s*\)\s*!=\s*\'\'/', $content)) {
	fwrite(STDERR, "syslog_get_import_xml_payload: trim(get_nfilter_request_var('import_text')) != '' condition missing in $target\n");
	exit(1);
}

/*
 * 2. Inside the textbox branch, it must return the
 *    untrimmed local.  A non-empty payload is preserved as-is.
 */
if (!preg_match('/return\s+get_nfilter_request_var\s*\(\s*\'import_text\'\s*\)\s*;/', $content)) {
	fwrite(STDERR, "syslog_get_import_xml_payload: return get_nfilter_request_var('import_text') assignment missing in $target\n");
	exit(1);
}

/*
 * 3. The file-upload branch must still exist (if on $_FILES).
 *    Ensures the fallback path was not accidentally removed.
 */
if (!preg_match('/if\s*\(\s*isset\s*\(\s*\$_FILES\s*\[/', $content)) {
	fwrite(STDERR, "syslog_get_import_xml_payload: \$_FILES if branch missing in $target\n");
	exit(1);
}

print "issue269_import_text_branch_logic_test passed\n";
