<?php

declare(strict_types=1);

/*
 * Integration tests for syslog post-processing maintenance.
 */

// Load stubs
require_once __DIR__ . '/../Helpers/GlobalStubs.php';

// Load the logic
require_once __DIR__ . '/../../functions.php';

describe('Syslog Post-Processing Integration', function () {
    beforeEach(function () {
        $GLOBALS['syslog_db_calls'] = [];
        $GLOBALS['syslog_db_results'] = [];
        $GLOBALS['syslog_test_config'] = [];
        $GLOBALS['syslog_hook_calls'] = [];
        $GLOBALS['syslogdb_default'] = 'cacti_syslog';
    });

    test('syslog_postprocess_tables() performs maintenance deletions', function () {
        $GLOBALS['syslog_test_config']['syslog_retention'] = 30;
        $GLOBALS['syslog_test_config']['syslog_alert_retention'] = 7;
        $GLOBALS['syslog_test_config']['syslog_statistics'] = 'on';

        syslog_postprocess_tables();

        // Should have deleted from:
        // 1. syslog_statistics
        // 2. syslog_logs
        // 3. syslog_hosts
        // 4. syslog_programs
        // 5. syslog_host_facilities

        $deleted_tables = array_map(function($call) {
            if ($call['method'] === 'execute_prepared') {
                if (preg_match('/DELETE FROM `cacti_syslog`\.`([^`]+)`/', $call['sql'], $matches)) {
                    return $matches[1];
                }
            }
            return null;
        }, $GLOBALS['syslog_db_calls']);

        expect($deleted_tables)->toContain('syslog_statistics');
        expect($deleted_tables)->toContain('syslog_logs');
        expect($deleted_tables)->toContain('syslog_hosts');
        expect($deleted_tables)->toContain('syslog_programs');
        expect($deleted_tables)->toContain('syslog_host_facilities');
    });

    test('syslog_postprocess_tables() triggers hooks', function () {
        $GLOBALS['syslog_test_config']['syslog_retention'] = 30;
        $GLOBALS['syslog_test_config']['syslog_alert_retention'] = 7;
        
        syslog_postprocess_tables();
        
        expect($GLOBALS['syslog_hook_calls'])->toHaveCount(1);
        expect($GLOBALS['syslog_hook_calls'][0]['name'])->toBe('syslog_delete_hostsalarm');
    });
});
