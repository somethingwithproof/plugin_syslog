<?php

$syslog_path  = dirname(__DIR__, 2) . '/syslog.php';
$reports_path = dirname(__DIR__, 2) . '/syslog_reports.php';
$removal_path = dirname(__DIR__, 2) . '/syslog_removal.php';
$alerts_path  = dirname(__DIR__, 2) . '/syslog_alerts.php';
$functions_js = dirname(__DIR__, 2) . '/js/functions.js';

foreach ([$syslog_path, $reports_path, $removal_path, $alerts_path, $functions_js] as $path) {
	if (!file_exists($path)) {
		fwrite(STDERR, "Failed to load required file for issue252 checks: $path\n");
		exit(1);
	}
}

/* php_strip_whitespace() removes all PHP comments before asserting, so a
   commented-out html_escape() call cannot satisfy the check and mask XSS. */
$syslogPhp   = php_strip_whitespace($syslog_path);
$reportsPhp  = php_strip_whitespace($reports_path);
$removalPhp  = php_strip_whitespace($removal_path);
$alertsPhp   = php_strip_whitespace($alerts_path);
$functionsJs = file_get_contents($functions_js);

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

if (strpos($alertsPhp, "html_escape(\$alert_info)") === false) {
	fwrite(STDERR, "Expected escaped alert confirmation list entries.\n");
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
