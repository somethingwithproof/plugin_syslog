<?php

declare(strict_types=1);

/*
 * Unit tests for syslog report SQL generation.
 */

// Load stubs
require_once __DIR__ . '/../Helpers/GlobalStubs.php';

// Load the logic
require_once __DIR__ . '/../../functions.php';

describe('Syslog Report SQL Generation', function () {
    beforeEach(function () {
        $GLOBALS['syslogdb_default'] = 'cacti_syslog';
    });

    test('syslog_get_report_sql() handles type messageb (begins with)', function () {
        $report = ['type' => 'messageb', 'message' => 'test_message'];
        $result = syslog_get_report_sql($report);
        
        expect($result['sql'])->toContain("WHERE message LIKE ?");
        expect($result['params'])->toContain("test_message%");
        expect($result['sql'])->toContain('FROM `cacti_syslog`.`syslog`');
    });

    test('syslog_get_report_sql() handles type messagec (contains)', function () {
        $report = ['type' => 'messagec', 'message' => 'test_message'];
        $result = syslog_get_report_sql($report);
        
        expect($result['sql'])->toContain("WHERE message LIKE ?");
        expect($result['params'])->toContain("%test_message%");
    });

    test('syslog_get_report_sql() handles type messagee (ends with)', function () {
        $report = ['type' => 'messagee', 'message' => 'test_message'];
        $result = syslog_get_report_sql($report);
        
        expect($result['sql'])->toContain("WHERE message LIKE ?");
        expect($result['params'])->toContain("%test_message");
    });

    test('syslog_get_report_sql() handles type host', function () {
        $report = ['type' => 'host', 'message' => 'test_host'];
        $result = syslog_get_report_sql($report);
        
        expect($result['sql'])->toContain("WHERE sh.host = ?");
        expect($result['params'])->toContain("test_host");
    });

    test('syslog_get_report_sql() handles type sql (raw sql)', function () {
        $report = ['type' => 'sql', 'message' => 'host_id = 5'];
        $result = syslog_get_report_sql($report);
        
        expect($result['sql'])->toContain("WHERE (host_id = 5)");
    });
});
