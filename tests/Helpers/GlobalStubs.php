<?php

declare(strict_types=1);

$GLOBALS['syslog_test_config'] = [];
$GLOBALS['syslog_db_calls'] = [];
$GLOBALS['syslog_db_results'] = [];
$GLOBALS['syslog_hook_calls'] = [];

if (!function_exists('cacti_sizeof')) {
    function cacti_sizeof($value) {
        if (is_array($value) || $value instanceof Countable) {
            return count($value);
        }
        return 0;
    }
}

function syslog_get_mock_result($method, $default = null) {
    if (!isset($GLOBALS['syslog_db_results'][$method])) {
        return $default;
    }
    
    $result = $GLOBALS['syslog_db_results'][$method];
    
    // Check if it's a sequential result (array of results)
    if (is_array($result) && isset($result[0]) && is_array($result[0]) && array_key_exists('__sequential', $result[0])) {
         // This is a bit complex for a simple stub, let's keep it simpler
    }
    
    // Simple sequential handler: if it's an array and we have a counter
    $counter_key = $method . '_counter';
    if (!isset($GLOBALS['syslog_db_results'][$counter_key])) {
        $GLOBALS['syslog_db_results'][$counter_key] = 0;
    }
    
    if (is_array($result) && isset($result[$GLOBALS['syslog_db_results'][$counter_key]])) {
        $val = $result[$GLOBALS['syslog_db_results'][$counter_key]];
        $GLOBALS['syslog_db_results'][$counter_key]++;
        return $val;
    }
    
    return $result;
}

if (!function_exists('db_affected_rows')) {
    function db_affected_rows($cnn) {
        return $GLOBALS['syslog_db_results']['db_affected_rows'] ?? 1;
    }
}

if (!function_exists('cacti_log')) {
    function cacti_log($message, $output = false, $facility = 'SYSTEM', $level = '') {
        // No-op for testing
    }
}

if (!function_exists('read_config_option')) {
    function read_config_option($name) {
        return $GLOBALS['syslog_test_config'][$name] ?? '';
    }
}

if (!function_exists('set_config_option')) {
    function set_config_option($name, $value) {
        $GLOBALS['syslog_test_config'][$name] = $value;
    }
}

if (!function_exists('syslog_db_fetch_row_prepared')) {
    function syslog_db_fetch_row_prepared($sql, $params = array(), $log = TRUE) {
        $GLOBALS['syslog_db_calls'][] = ['method' => 'fetch_row_prepared', 'sql' => $sql, 'params' => $params];
        return syslog_get_mock_result('fetch_row_prepared', array());
    }
}

if (!function_exists('syslog_db_fetch_assoc_prepared')) {
    function syslog_db_fetch_assoc_prepared($sql, $params = array(), $log = TRUE) {
        $GLOBALS['syslog_db_calls'][] = ['method' => 'fetch_assoc_prepared', 'sql' => $sql, 'params' => $params];
        return syslog_get_mock_result('fetch_assoc_prepared', array());
    }
}

if (!function_exists('syslog_db_fetch_cell_prepared')) {
    function syslog_db_fetch_cell_prepared($sql, $params = array(), $log = TRUE) {
        $GLOBALS['syslog_db_calls'][] = ['method' => 'fetch_cell_prepared', 'sql' => $sql, 'params' => $params];
        return syslog_get_mock_result('fetch_cell_prepared', '');
    }
}

if (!function_exists('syslog_db_execute_prepared')) {
    function syslog_db_execute_prepared($sql, $params = array(), $log = TRUE) {
        $GLOBALS['syslog_db_calls'][] = ['method' => 'execute_prepared', 'sql' => $sql, 'params' => $params];
        return true;
    }
}

if (!function_exists('syslog_db_execute')) {
    function syslog_db_execute($sql, $log = TRUE) {
        $GLOBALS['syslog_db_calls'][] = ['method' => 'execute', 'sql' => $sql];
        return true;
    }
}

if (!function_exists('syslog_db_fetch_assoc')) {
    function syslog_db_fetch_assoc($sql, $log = TRUE) {
        $GLOBALS['syslog_db_calls'][] = ['method' => 'fetch_assoc', 'sql' => $sql];
        return syslog_get_mock_result('fetch_assoc', array());
    }
}

if (!function_exists('db_fetch_row_prepared')) {
    function db_fetch_row_prepared($sql, $params = array(), $log = TRUE) {
        $GLOBALS['syslog_db_calls'][] = ['method' => 'db_fetch_row', 'sql' => $sql, 'params' => $params];
        return syslog_get_mock_result('db_fetch_row', array());
    }
}

if (!function_exists('db_fetch_cell_prepared')) {
    function db_fetch_cell_prepared($sql, $params = array(), $log = TRUE) {
        $GLOBALS['syslog_db_calls'][] = ['method' => 'db_fetch_cell', 'sql' => $sql, 'params' => $params];
        return syslog_get_mock_result('db_fetch_cell', '');
    }
}

if (!function_exists('db_fetch_assoc')) {
    function db_fetch_assoc($sql, $log = TRUE) {
        $GLOBALS['syslog_db_calls'][] = ['method' => 'db_fetch_assoc', 'sql' => $sql];
        return syslog_get_mock_result('db_fetch_assoc', array());
    }
}

if (!function_exists('db_fetch_assoc_prepared')) {
    function db_fetch_assoc_prepared($sql, $params = array(), $log = TRUE) {
        $GLOBALS['syslog_db_calls'][] = ['method' => 'db_fetch_assoc_prepared', 'sql' => $sql, 'params' => $params];
        return syslog_get_mock_result('db_fetch_assoc_prepared', array());
    }
}

if (!function_exists('db_qstr')) {
    function db_qstr($string) {
        return "'" . addslashes((string)$string) . "'";
    }
}

if (!function_exists('html_escape')) {
    function html_escape($string) {
        return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('html_escape_request_var')) {
    function html_escape_request_var($name) {
        return html_escape($_POST[$name] ?? $_GET[$name] ?? '');
    }
}

if (!function_exists('api_plugin_hook_function')) {
    function api_plugin_hook_function($name, $params = array()) {
        $GLOBALS['syslog_hook_calls'][] = ['name' => $name, 'params' => $params];
    }
}

if (!function_exists('__')) {
    function __($str, $domain) {
        return $str;
    }
}

if (!function_exists('__esc')) {
    function __esc($str, $arg1 = null, $arg2 = null) {
        if ($arg1 !== null) {
             return sprintf($str, $arg1);
        }
        return $str;
    }
}
