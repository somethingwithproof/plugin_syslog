<?php
/*
 * Source-scan lint for the #259 purge-syslog-hosts CSRF hardening.
 *
 * This is a lint, NOT a behavioral test. It asserts that the expected
 * CSRF-enforcement snippets exist in setup.php so regressions that
 * silently delete the POST/csrf_check/JS-post-flow are caught at CI
 * time. A full behavioral test would require bootstrapping Cacti's
 * session, CSRF token, and database, which this plugin's regression
 * harness cannot currently provide.
 *
 * If the guard is refactored in a way that preserves the strings but
 * breaks the logic, this lint will NOT catch it — follow-up issue for
 * real behavioral coverage once a DB-backed test harness exists.
 */

$setup     = file_get_contents(dirname(__DIR__, 2) . '/setup.php');
$functions = file_get_contents(dirname(__DIR__, 2) . '/functions.php');

if ($setup === false || $functions === false) {
	fwrite(STDERR, "Failed to read setup.php or functions.php\n");
	exit(1);
}

$required = [
	"if (\$_SERVER['REQUEST_METHOD'] !== 'POST')",
	"if (!function_exists('csrf_check'))",
	"if (!csrf_check(false))",
	"__csrf_magic: csrfMagicToken"
];

foreach ($required as $snippet) {
	if (!str_contains($setup, $snippet)) {
		fwrite(STDERR, "Missing expected CSRF hardening snippet: $snippet\n");
		exit(1);
	}
}

if (str_contains($setup, "href='utilities.php?action=purge_syslog_hosts'")) {
	fwrite(STDERR, "Legacy GET purge link still present.\n");
	exit(1);
}

// Verify fail-closed: the else branch (csrf_check unavailable) must reject, not fall through.
// Assert globally safe properties rather than parsing the else block via brittle regex.

// The fallback path must not attempt manual token checking
if (str_contains($setup, "\$_POST['__csrf_magic']")) {
	fwrite(STDERR, "Fallback CSRF branch must not check token presence; must fail closed.\n");
	exit(1);
}

// The fallback path must log the blocked attempt
if (!str_contains($setup, "cacti_log('WARNING: syslog purge blocked")) {
	fwrite(STDERR, "Fail-closed branch must call cacti_log() to audit blocked purge attempts.\n");
	exit(1);
}

// Log message must name the specific failure reason for incident response
if (!str_contains($setup, 'CSRF validation unavailable')) {
	fwrite(STDERR, "Log message must specify 'CSRF validation unavailable' for operational clarity.\n");
	exit(1);
}

// Verify JS confirm() uses json_encode, not __esc() inside JS string
if (preg_match("/confirm\(\s*'/", $setup)) {
	fwrite(STDERR, "JS confirm() must use json_encode() for safe encoding, not __esc() in a quoted string.\n");
	exit(1);
}

if (!str_contains($setup, "syslog_json_encode_for_script(__('Confirm Purge', 'syslog'))")) {
	fwrite(STDERR, "Expected syslog_json_encode_for_script() for JS-safe dialog title encoding.\n");
	exit(1);
}

if (!str_contains($setup, "syslog_json_encode_for_script(__('Cancel', 'syslog'))") ||
	!str_contains($setup, "syslog_json_encode_for_script(__('Continue', 'syslog'))")) {
	fwrite(STDERR, "Expected syslog_json_encode_for_script() for JS-safe button text encoding.\n");
	exit(1);
}

// Verify json_encode uses JSON_HEX_TAG to prevent </script> breakout in HTML script context
if (!str_contains($functions, 'JSON_HEX_TAG')) {
	fwrite(STDERR, "json_encode() must use JSON_HEX_TAG to prevent script-context breakout.\n");
	exit(1);
}

if (!str_contains($functions, 'JSON_HEX_AMP')) {
	fwrite(STDERR, "json_encode() must use JSON_HEX_AMP to escape ampersands in script context.\n");
	exit(1);
}

if (!str_contains($functions, 'JSON_HEX_APOS')) {
	fwrite(STDERR, "json_encode() must use JSON_HEX_APOS.\n");
	exit(1);
}

if (!str_contains($functions, 'JSON_HEX_QUOT')) {
	fwrite(STDERR, "json_encode() must use JSON_HEX_QUOT.\n");
	exit(1);
}

// Verify user-facing messages do not expose CSRF internals (log messages may use "CSRF")
if (preg_match('/raise_message\\s*\\(\\s*[^,]+,\\s*__\\(\\s*([\'\"])[^\'\"]*CSRF[^\'\"]*\\1/si', $setup)) {
	fwrite(STDERR, "User-facing raise_message must not expose CSRF internals to end users.\n");
	exit(1);
}

// Verify generic user-facing message is present
if (!str_contains($setup, 'Invalid request. Please try again.')) {
	fwrite(STDERR, "Fail-closed branch must use generic 'Invalid request. Please try again.' message.\n");
	exit(1);
}

// Verify fail-closed raise_message uses MESSAGE_LEVEL_ERROR severity
if (!str_contains($setup, "raise_message('syslog_csrf_unavailable', __('Invalid request. Please try again.', 'syslog'), MESSAGE_LEVEL_ERROR)")) {
	fwrite(STDERR, "Fail-closed branch raise_message must use MESSAGE_LEVEL_ERROR severity.\n");
	exit(1);
}

// Verify log message does not expose internal function name
if (str_contains($setup, 'csrf_check() unavailable')) {
	fwrite(STDERR, "Log message must not name internal validation function.\n");
	exit(1);
}

echo "issue259_csrf_purge_test passed\n";
