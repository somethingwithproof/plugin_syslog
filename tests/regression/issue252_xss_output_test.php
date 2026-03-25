<?php

$syslogPhp   = file_get_contents(dirname(__DIR__, 2) . '/syslog.php');
$reportsPhp  = file_get_contents(dirname(__DIR__, 2) . '/syslog_reports.php');
$removalPhp  = file_get_contents(dirname(__DIR__, 2) . '/syslog_removal.php');
$functionsJs = file_get_contents(dirname(__DIR__, 2) . '/js/functions.js');

if ($syslogPhp === false || $reportsPhp === false || $removalPhp === false || $functionsJs === false) {
	fwrite(STDERR, "Failed to load one or more plugin files for issue252 checks.\n");
	exit(1);
}

if (substr_count($syslogPhp, "html_escape(\$host['host'])") < 2) {
	fwrite(STDERR, "Expected escaped host rendering in syslog.php output paths.\n");
	exit(1);
}

if (strpos($reportsPhp, "html_escape(\$report_info)") === false) {
	fwrite(STDERR, "Expected escaped report confirmation list entries.\n");
	exit(1);
}

if (strpos($removalPhp, "html_escape(\$removal_info)") === false) {
	fwrite(STDERR, "Expected escaped removal confirmation list entries.\n");
	exit(1);
}

if (strpos($reportsPhp, "form_selectable_ecell(\$report['message'], \$report['id']);") === false) {
	fwrite(STDERR, "Expected escaped report message cell rendering.\n");
	exit(1);
}

if (strpos($functionsJs, "var option = $('<option>')") === false ||
	strpos($functionsJs, ".text(hostData.host);") === false ||
	strpos($functionsJs, "$('#host').append(option);") === false) {
	fwrite(STDERR, "Expected DOM-safe host option rendering in js/functions.js.\n");
	exit(1);
}

if (strpos($functionsJs, "$('#host').append('<option class=\"'+hostData.class+'\" value=\"'+index+'\">'+hostData.host+'</option>');") !== false) {
	fwrite(STDERR, "Legacy unsafe host option HTML concatenation still present.\n");
	exit(1);
}

print "issue252_xss_output_test passed\n";
