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

if (strpos($syslog, "pageTab: <?php print json_encode(get_request_var('tab'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>,") === false) {
	fwrite(STDERR, "Expected JSON-encoded syslog pageTab value\n");
	exit(1);
}

foreach (array(
	"json_encode(__('Enter a search term', 'syslog'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)",
	"json_encode(__('Select Device(s)', 'syslog'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)",
	"json_encode(__('Devices Selected', 'syslog'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)",
	"json_encode(__('All Devices Selected', 'syslog'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)",
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
