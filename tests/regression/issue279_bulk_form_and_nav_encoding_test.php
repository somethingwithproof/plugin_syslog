<?php

$targets = array(
	'syslog_removal.php' => file_get_contents(__DIR__ . '/../../syslog_removal.php'),
	'syslog_alerts.php'  => file_get_contents(__DIR__ . '/../../syslog_alerts.php'),
	'syslog_reports.php' => file_get_contents(__DIR__ . '/../../syslog_reports.php'),
	'syslog.php'         => file_get_contents(__DIR__ . '/../../syslog.php'),
);

foreach ($targets as $file => $contents) {
	if ($contents === false) {
		fwrite(STDERR, "Unable to read $file\n");
		exit(1);
	}
}

foreach (array('syslog_removal.php', 'syslog_alerts.php', 'syslog_reports.php') as $file) {
	if (strpos($targets[$file], "html_escape(get_request_var('drp_action'))") === false) {
		fwrite(STDERR, "Expected escaped drp_action hidden field in $file\n");
		exit(1);
	}

	if (strpos($targets[$file], "rawurlencode(get_request_var('filter'))") === false) {
		fwrite(STDERR, "Expected URL-encoded filter nav value in $file\n");
		exit(1);
	}

	if (strpos($targets[$file], "<input type='hidden' name='drp_action' value='\" . get_request_var('drp_action') . \"'>") !== false) {
		fwrite(STDERR, "Legacy raw drp_action hidden field remains in $file\n");
		exit(1);
	}
}

$syslog = $targets['syslog.php'];

if (strpos($syslog, "pageTab: <?php print syslog_json_encode_for_script(get_request_var('tab'));?>,") === false) {
	fwrite(STDERR, "Expected JSON-encoded syslog pageTab value\n");
	exit(1);
}

foreach (array(
	"syslog_json_encode_for_script(__('Enter a search term', 'syslog'))",
	"syslog_json_encode_for_script(__('Select Device(s)', 'syslog'))",
	"syslog_json_encode_for_script(__('Devices Selected', 'syslog'))",
	"syslog_json_encode_for_script(__('All Devices Selected', 'syslog'))",
) as $needle) {
	if (strpos($syslog, $needle) === false) {
		fwrite(STDERR, "Expected JS-safe initSyslogMain text encoding\n");
		exit(1);
	}
}

if (strpos($syslog, "pageTab: '<?php print get_request_var('tab'); ?>'") !== false) {
	fwrite(STDERR, "Legacy raw pageTab JS assignment still present\n");
	exit(1);
}

echo "OK\n";
