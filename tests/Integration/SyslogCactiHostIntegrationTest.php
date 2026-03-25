<?php

declare(strict_types=1);

/*
 * Integration tests for syslog cacti host resolution.
 *
 * This test verifies that we can correctly resolve hostnames to Cacti descriptions.
 */

// Load stubs
require_once __DIR__ . '/../Helpers/GlobalStubs.php';

// Load the logic
require_once __DIR__ . '/../../functions.php';

describe('Syslog Cacti Host Integration', function () {
    beforeEach(function () {
        $GLOBALS['syslog_db_calls'] = [];
        $GLOBALS['syslog_db_results'] = [];
        $GLOBALS['syslog_test_config'] = [];
        $GLOBALS['syslogdb_default'] = 'cacti_syslog';
    });

    test('syslog_check_cacti_hosts() returns false for empty host', function () {
        expect(syslog_check_cacti_hosts('', 12345))->toBeFalse();
        expect($GLOBALS['syslog_db_calls'])->toBeEmpty();
    });

    test('syslog_check_cacti_hosts() returns false when host not found in Cacti', function () {
        $GLOBALS['syslog_db_results']['db_fetch_row'] = array();
        
        $result = syslog_check_cacti_hosts('unknown-host', 12345);
        
        expect($result)->toBeFalse();
        expect($GLOBALS['syslog_db_calls'])->toHaveCount(1);
        expect($GLOBALS['syslog_db_calls'][0]['method'])->toBe('db_fetch_row');
        expect($GLOBALS['syslog_db_calls'][0]['params'])->toBe(['unknown-host']);
    });

    test('syslog_check_cacti_hosts() returns true and updates when host is found in Cacti', function () {
        $GLOBALS['syslog_db_results']['db_fetch_row'] = array('description' => 'Resolved Name');
        
        $result = syslog_check_cacti_hosts('1.2.3.4', 12345);
        
        expect($result)->toBeTrue();
        expect($GLOBALS['syslog_db_calls'])->toHaveCount(2);
        
        // Check lookup
        expect($GLOBALS['syslog_db_calls'][0]['method'])->toBe('db_fetch_row');
        expect($GLOBALS['syslog_db_calls'][0]['params'])->toBe(['1.2.3.4']);
        
        // Check update
        expect($GLOBALS['syslog_db_calls'][1]['method'])->toBe('execute_prepared');
        expect($GLOBALS['syslog_db_calls'][1]['params'])->toBe(['Resolved Name', '1.2.3.4', 12345]);
        expect($GLOBALS['syslog_db_calls'][1]['sql'])->toContain('UPDATE `cacti_syslog`.`syslog_incoming`');
    });

    test('syslog_update_reference_tables() resolves hostnames via Cacti lookup', function () {
        $uniqueID = 54321;
        $GLOBALS['syslog_test_config']['syslog_resolve_hostname'] = 'on';
        $GLOBALS['syslog_test_config']['syslog_no_dns'] = 'on';
        
        // Mock fetch of hosts from syslog_incoming. Need to wrap in extra array for sequence mock
        $GLOBALS['syslog_db_results']['fetch_assoc_prepared'] = [
            [ array('host' => '10.0.0.1') ]
        ];
        
        // Mock Cacti host lookup for 10.0.0.1
        $GLOBALS['syslog_db_results']['db_fetch_row'] = [ array('description' => 'Server-01') ];
        
        syslog_update_reference_tables($uniqueID);
        
        // Should have called:
        // 1. fetch_assoc_prepared (get hosts)
        // 2. db_fetch_row (syslog_check_cacti_hosts)
        // 3. execute (update hostname in syslog_incoming)
        // ... (other calls like syslog_programs, syslog_hosts)
        
        $foundLookup = false;
        $foundUpdate = false;
        
        foreach ($GLOBALS['syslog_db_calls'] as $call) {
            if ($call['method'] === 'db_fetch_row' && isset($call['params'][0]) && $call['params'][0] === '10.0.0.1') {
                $foundLookup = true;
            }
            if ($call['method'] === 'execute_prepared' && strpos($call['sql'], 'UPDATE `cacti_syslog`.`syslog_incoming`') !== false && isset($call['params'][0]) && $call['params'][0] === 'Server-01') {
                $foundUpdate = true;
            }
        }
        
        expect($foundLookup)->toBeTrue();
        expect($foundUpdate)->toBeTrue();
    });

    test('syslog_update_reference_tables() marks hosts as unresolved if lookup fails', function () {
        $uniqueID = 999;
        $GLOBALS['syslog_test_config']['syslog_resolve_hostname'] = 'on';
        $GLOBALS['syslog_test_config']['syslog_no_dns'] = 'on';
        
        // Mock fetch of hosts from syslog_incoming
        $GLOBALS['syslog_db_results']['fetch_assoc_prepared'] = [
            [ array('host' => 'unresolvable.local') ]
        ];
        
        // Mock Cacti host lookup as empty (not found)
        $GLOBALS['syslog_db_results']['db_fetch_row'] = [ array() ];
        
        syslog_update_reference_tables($uniqueID);
        
        $foundUpdate = false;
        foreach ($GLOBALS['syslog_db_calls'] as $call) {
            if ($call['method'] === 'execute_prepared' && strpos($call['sql'], 'UPDATE `cacti_syslog`.`syslog_incoming`') !== false && isset($call['params'][0]) && $call['params'][0] === 'unresolved-unresolvable.local') {
                $foundUpdate = true;
            }
        }
        expect($foundUpdate)->toBeTrue();
    });
});
