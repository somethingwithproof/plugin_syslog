<?php

declare(strict_types=1);

// Stubs for Cacti core functions used in functions.php
if (!function_exists('get_nfilter_request_var')) {
    function get_nfilter_request_var($name) { return $_POST[$name] ?? $_GET[$name] ?? ''; }
}
if (!function_exists('get_request_var')) {
    function get_request_var($name) { return $_POST[$name] ?? $_GET[$name] ?? ''; }
}
if (!function_exists('__')) { function __($str, $domain) { return $str; } }
if (!function_exists('cacti_log')) { function cacti_log($msg, $stderr, $fac) {} }

require_once __DIR__ . '/../../functions.php';

test('syslog_get_import_xml_payload loads from text area', function () {
    $_POST['import_text'] = '<xml>test</xml>';
    $payload = syslog_get_import_xml_payload('http://localhost');
    expect($payload)->toBe('<xml>test</xml>');
});
