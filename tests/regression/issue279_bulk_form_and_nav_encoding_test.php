<?php

$targets = [
	'syslog_removal.php' => file_get_contents(__DIR__ . '/../../syslog_removal.php'),
	'syslog_alerts.php'  => file_get_contents(__DIR__ . '/../../syslog_alerts.php'),
	'syslog_reports.php' => file_get_contents(__DIR__ . '/../../syslog_reports.php'),
	'syslog.php'         => file_get_contents(__DIR__ . '/../../syslog.php'),
];

foreach ($targets as $file => $contents) {
	if ($contents === false) {
		fwrite(STDERR, "Unable to read $file\n");
		exit(1);
	}
}

foreach (['syslog_removal.php', 'syslog_alerts.php', 'syslog_reports.php'] as $file) {
	if (!str_contains($targets[$file], "html_escape(get_request_var('drp_action'))")) {
		fwrite(STDERR, "Expected escaped drp_action hidden field in $file\n");
		exit(1);
	}

	if (!str_contains($targets[$file], "rawurlencode(get_request_var('filter'))")) {
		fwrite(STDERR, "Expected URL-encoded filter nav value in $file\n");
		exit(1);
	}

	if (str_contains($targets[$file], "<input type='hidden' name='drp_action' value='\" . get_request_var('drp_action') . \"'>")) {
		fwrite(STDERR, "Legacy raw drp_action hidden field remains in $file\n");
		exit(1);
	}
}

$syslog = $targets['syslog.php'];

if (!str_contains($syslog, "pageTab: <?php print syslog_json_safe(get_request_var('tab'));?>,")) {
	fwrite(STDERR, "Expected JSON-encoded syslog pageTab value\n");
	exit(1);
}

foreach ([
	"syslog_json_safe(__('Enter a search term', 'syslog'))",
	"syslog_json_safe(__('Select Device(s)', 'syslog'))",
	"syslog_json_safe(__('Devices Selected', 'syslog'))",
	"syslog_json_safe(__('All Devices Selected', 'syslog'))",
] as $needle) {
	if (!str_contains($syslog, $needle)) {
		fwrite(STDERR, "Expected JS-safe initSyslogMain text encoding\n");
		exit(1);
	}
}

if (str_contains($syslog, "pageTab: '<?php print get_request_var('tab'); ?>'")) {
	fwrite(STDERR, "Legacy raw pageTab JS assignment still present\n");
	exit(1);
}

echo "OK\n";
