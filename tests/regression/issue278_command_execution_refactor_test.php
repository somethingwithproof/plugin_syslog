<?php

$functions = file_get_contents(dirname(__DIR__, 2) . '/functions.php');

if ($functions === false) {
	fwrite(STDERR, "Failed to load functions.php\n");
	exit(1);
}

if (strpos($functions, 'function syslog_execute_ticket_command(') === false) {
	fwrite(STDERR, "Ticket command execution helper is missing.\n");
	exit(1);
}

if (strpos($functions, 'function syslog_execute_alert_command(') === false) {
	fwrite(STDERR, "Alert command execution helper is missing.\n");
	exit(1);
}

/* Match only call sites (two args), not the function definition.  Two call
 * sites exist: one for method==0 and one for method==1. */
if (substr_count($functions, 'syslog_execute_ticket_command($alert, $hostlist);') < 2) {
	fwrite(STDERR, "syslog_process_alerts() is not consistently using the ticket command helper.\n");
	exit(1);
}

if (substr_count($functions, 'syslog_execute_alert_command($alert, $results, $hostname);') < 2) {
	fwrite(STDERR, "syslog_process_alerts() is not consistently using the alert command helper.\n");
	exit(1);
}

if (strpos($functions, "exec('/bin/sh '") !== false) {
	fwrite(STDERR, "Shell fallback execution path must not appear in shared helpers.\n");
	exit(1);
}

/* open_ticket guard: function is a no-op unless open_ticket == 'on' */
if (strpos($functions, "\$alert['open_ticket'] == 'on'") === false) {
	fwrite(STDERR, "syslog_execute_ticket_command() must guard on open_ticket == 'on'.\n");
	exit(1);
}

/* empty-command guard: function is a no-op when command trims to '' */
if (strpos($functions, "\$command != ''") === false) {
	fwrite(STDERR, "syslog_execute_ticket_command() must guard on non-empty command.\n");
	exit(1);
}

/* is_executable must be called on the resolved path, not the raw command string.
 * Old code checked is_executable($command) which broke quoted absolute paths.
 * realpath() resolves symlinks and relative segments before the check. */
if (strpos($functions, 'is_executable($resolved)') === false) {
	fwrite(STDERR, "is_executable() must be called on realpath-resolved \$resolved, not raw \$command.\n");
	exit(1);
}

if (strpos($functions, 'is_executable($command)') !== false) {
	fwrite(STDERR, "is_executable() must not be called on raw \$command string.\n");
	exit(1);
}

/* realpath() must be used to resolve executable path (prevents path traversal) */
if (substr_count($functions, 'realpath($executable)') < 2) {
	fwrite(STDERR, "Both helpers must use realpath() to resolve the executable path.\n");
	exit(1);
}

/* PATH-lookup detection: both helpers must produce distinct error messages via
 * DIRECTORY_SEPARATOR to distinguish "no path separator" from "not executable". */
if (substr_count($functions, 'strpos($executable, DIRECTORY_SEPARATOR)') < 2) {
	fwrite(STDERR, "Both helpers must detect PATH-based lookups via DIRECTORY_SEPARATOR.\n");
	exit(1);
}

/* syslog_execute_alert_command must delegate variable substitution to
 * alert_replace_variables(); an empty result falls through to is_executable('')
 * which returns false and logs the error path. */
if (strpos($functions, 'alert_replace_variables(') === false) {
	fwrite(STDERR, "syslog_execute_alert_command() must call alert_replace_variables().\n");
	exit(1);
}

/* ticket command must only log on failure ($return != 0), not unconditionally */
if (preg_match('/exec\(\$command,.*?\$return\);\s*\n\s*cacti_log\(sprintf\(\'SYSLOG NOTICE:/s', $functions)) {
	fwrite(STDERR, "syslog_execute_ticket_command() must not unconditionally log success after exec().\n");
	exit(1);
}

/* syslog_execute_alert_command must not have dead assignment $returnCode = 126 */
if (preg_match('/\$returnCode\s*=\s*126/', $functions)) {
	fwrite(STDERR, "syslog_execute_alert_command() must not contain dead assignment \$returnCode = 126.\n");
	exit(1);
}

/* quote-stripping: executable extraction must trim surrounding quotes */
if (strpos($functions, "trim(\$cparts[0], '\"\\'')") === false) {
	fwrite(STDERR, "Executable extraction must strip surrounding quotes from command path.\n");
	exit(1);
}

/* preg_split for whitespace tokenization (handles tabs and consecutive spaces) */
if (substr_count($functions, "preg_split('/\\s+/', trim(\$command))") < 1) {
	fwrite(STDERR, "Command tokenization must use preg_split for whitespace splitting.\n");
	exit(1);
}

/* non-executable error path must log SYSLOG ERROR in both helpers */
if (substr_count($functions, 'SYSLOG ERROR:') < 2) {
	fwrite(STDERR, "Both helpers must log SYSLOG ERROR when executable is missing.\n");
	exit(1);
}

/* cacti_escapeshellarg must wrap all four --arg values in ticket command */
$ticket_fn_match = array();
if (preg_match('/function syslog_execute_ticket_command\b.*?^}/ms', $functions, $ticket_fn_match)) {
	$ticket_body = $ticket_fn_match[0];
	$esc_count   = substr_count($ticket_body, 'cacti_escapeshellarg(');
	if ($esc_count < 4) {
		fwrite(STDERR, "syslog_execute_ticket_command() must call cacti_escapeshellarg() for all 4 --arg values (found $esc_count).\n");
		exit(1);
	}
}

/* hostlist sanitization: shared helper must exist and contain validation logic */
if (strpos($functions, 'function syslog_sanitize_hostlist(') === false) {
	fwrite(STDERR, "syslog_sanitize_hostlist() shared helper must exist.\n");
	exit(1);
}

if (strpos($functions, "array_filter(array_map('trim', \$hostlist))") === false) {
	fwrite(STDERR, "syslog_sanitize_hostlist() must sanitize hostlist entries via array_filter/array_map.\n");
	exit(1);
}

/* hostlist validation: helper must validate against a hostname/IP character whitelist */
if (strpos($functions, "preg_match('/^[a-zA-Z0-9") === false) {
	fwrite(STDERR, "syslog_sanitize_hostlist() must validate hostlist entries against hostname/IP character whitelist.\n");
	exit(1);
}

/* both call sites must use the shared helper */
if (substr_count($functions, 'syslog_sanitize_hostlist(') < 3) {
	fwrite(STDERR, "syslog_sanitize_hostlist() must be called from both syslog_execute_ticket_command() and alert_setup_environment().\n");
	exit(1);
}

/* read_config_option return must be validated for false/null before cast to string */
if (strpos($functions, "read_config_option('syslog_ticket_command')") === false) {
	fwrite(STDERR, "syslog_execute_ticket_command() must call read_config_option().\n");
	exit(1);
}

if (strpos($functions, '!== false') === false || strpos($functions, '!== null') === false) {
	fwrite(STDERR, "syslog_execute_ticket_command() must validate read_config_option() for false/null.\n");
	exit(1);
}

/* error logs must include the command for debugging context */
$ticket_fn_match = array();
if (preg_match('/function syslog_execute_ticket_command\b.*?^}/ms', $functions, $ticket_fn_match)) {
	if (strpos($ticket_fn_match[0], 'Command:%s') === false) {
		fwrite(STDERR, "syslog_execute_ticket_command() error log must include the command.\n");
		exit(1);
	}
}

$alert_fn_match = array();
if (preg_match('/function syslog_execute_alert_command\b.*?^}/ms', $functions, $alert_fn_match)) {
	if (strpos($alert_fn_match[0], 'Command:%s') === false) {
		fwrite(STDERR, "syslog_execute_alert_command() error log must include the command.\n");
		exit(1);
	}
}

/* ticket command helper must contain its own hardcoded error format */
if (strpos($functions, "'ERROR: Ticket Command Failed.") === false) {
	fwrite(STDERR, "syslog_execute_ticket_command() must contain hardcoded 'ERROR: Ticket Command Failed.' format.\n");
	exit(1);
}

/* alert_replace_variables must escape all substituted tokens with cacti_escapeshellarg */
$arv_match = array();
if (preg_match('/function alert_replace_variables\b.*?^}/ms', $functions, $arv_match)) {
	$arv_body  = $arv_match[0];
	$arv_count = substr_count($arv_body, 'cacti_escapeshellarg(');
	if ($arv_count < 6) {
		fwrite(STDERR, "alert_replace_variables() must call cacti_escapeshellarg() for all 6 tokens (found $arv_count).\n");
		exit(1);
	}
}

/* syslog_execute_alert_command must guard against empty command from alert_replace_variables */
$alert_fn_match2 = array();
if (preg_match('/function syslog_execute_alert_command\b.*?^}/ms', $functions, $alert_fn_match2)) {
	if (strpos($alert_fn_match2[0], "trim(\$command) == ''") === false) {
		fwrite(STDERR, "syslog_execute_alert_command() must validate that command is non-empty.\n");
		exit(1);
	}
}

/* both helpers must log on success */
if (strpos($functions, 'Ticket command succeeded') === false) {
	fwrite(STDERR, "syslog_execute_ticket_command() must log successful executions.\n");
	exit(1);
}

if (strpos($functions, 'Alert command succeeded') === false) {
	fwrite(STDERR, "syslog_execute_alert_command() must log successful executions.\n");
	exit(1);
}

/* ticket command must validate clean_up_name() result is non-empty */
$ticket_fn_match3 = array();
if (preg_match('/function syslog_execute_ticket_command\b.*?^}/ms', $functions, $ticket_fn_match3)) {
	if (strpos($ticket_fn_match3[0], 'clean_up_name(') === false) {
		fwrite(STDERR, "syslog_execute_ticket_command() must call clean_up_name().\n");
		exit(1);
	}
	if (strpos($ticket_fn_match3[0], "== ''") === false) {
		fwrite(STDERR, "syslog_execute_ticket_command() must validate clean_up_name() result is non-empty.\n");
		exit(1);
	}
}

/* alert_replace_variables must cap hostname length to 253 */
$arv_match2 = array();
if (preg_match('/function alert_replace_variables\b.*?^}/ms', $functions, $arv_match2)) {
	if (strpos($arv_match2[0], 'substr(') === false) {
		fwrite(STDERR, "alert_replace_variables() must cap hostname and message length via substr().\n");
		exit(1);
	}
	if (strpos($arv_match2[0], '253') === false) {
		fwrite(STDERR, "alert_replace_variables() must cap hostname to 253 characters.\n");
		exit(1);
	}
	if (strpos($arv_match2[0], '65536') === false) {
		fwrite(STDERR, "alert_replace_variables() must cap message to 65536 characters.\n");
		exit(1);
	}
}

/* ticket command must log sanitized hostlist at debug level */
$ticket_fn_match4 = array();
if (preg_match('/function syslog_execute_ticket_command\b.*?^}/ms', $functions, $ticket_fn_match4)) {
	if (strpos($ticket_fn_match4[0], 'hostlist after sanitization') === false) {
		fwrite(STDERR, "syslog_execute_ticket_command() must log sanitized hostlist for debugging.\n");
		exit(1);
	}
}

/* both error formats must use sprintf for consistency */
if (preg_match('/function syslog_execute_ticket_command\b.*?^}/ms', $functions, $ticket_fn_match4)) {
	if (strpos($ticket_fn_match4[0], "sprintf('ERROR: Ticket Command Failed.") === false) {
		fwrite(STDERR, "syslog_execute_ticket_command() must use sprintf for error format.\n");
		exit(1);
	}
}

/* success logs must not include full command at normal verbosity */
$ticket_fn_match5 = array();
if (preg_match('/function syslog_execute_ticket_command\b.*?^}/ms', $functions, $ticket_fn_match5)) {
	if (preg_match('/SYSLOG NOTICE: Ticket command succeeded\..*Command/', $ticket_fn_match5[0])
		&& strpos($ticket_fn_match5[0], 'POLLER_VERBOSITY_DEBUG') === false) {
		fwrite(STDERR, "syslog_execute_ticket_command() must gate command logging behind POLLER_VERBOSITY_DEBUG.\n");
		exit(1);
	}
}

$alert_fn_match3 = array();
if (preg_match('/function syslog_execute_alert_command\b.*?^}/ms', $functions, $alert_fn_match3)) {
	if (preg_match('/SYSLOG NOTICE: Alert command succeeded\..*Command/', $alert_fn_match3[0])
		&& strpos($alert_fn_match3[0], 'POLLER_VERBOSITY_DEBUG') === false) {
		fwrite(STDERR, "syslog_execute_alert_command() must gate command logging behind POLLER_VERBOSITY_DEBUG.\n");
		exit(1);
	}
}

/* syslog_execute_alert_command must guard against empty command */
$alert_fn_match4 = array();
if (preg_match('/function syslog_execute_alert_command\b.*?^}/ms', $functions, $alert_fn_match4)) {
	if (strpos($alert_fn_match4[0], "trim(\$command) == ''") === false) {
		fwrite(STDERR, "syslog_execute_alert_command() must guard against empty command from alert_replace_variables().\n");
		exit(1);
	}
}

/* syslog_execute_alert_command must reject path traversal via realpath */
if (preg_match('/function syslog_execute_alert_command\b.*?^}/ms', $functions, $alert_fn_match4)) {
	if (strpos($alert_fn_match4[0], 'realpath(') === false) {
		fwrite(STDERR, "syslog_execute_alert_command() must use realpath() to prevent path traversal.\n");
		exit(1);
	}
}

/* read_config_option must be validated for false/null before use */
if (strpos($functions, '!== false') === false || strpos($functions, '!== null') === false) {
	fwrite(STDERR, "syslog_execute_ticket_command() must validate read_config_option() return for false/null.\n");
	exit(1);
}

/* both success log formats must use sprintf for consistency */
$ticket_fn_match6 = array();
if (preg_match('/function syslog_execute_ticket_command\b.*?^}/ms', $functions, $ticket_fn_match6)) {
	if (strpos($ticket_fn_match6[0], "sprintf('SYSLOG NOTICE: Ticket command succeeded.") === false) {
		fwrite(STDERR, "syslog_execute_ticket_command() must use sprintf for success log format.\n");
		exit(1);
	}
}

echo "issue278_command_execution_refactor_test passed\n";
