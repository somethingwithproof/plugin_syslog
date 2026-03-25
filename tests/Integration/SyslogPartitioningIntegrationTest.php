<?php

declare(strict_types=1);

/*
 * Integration tests for syslog partitioning logic.
 *
 * This test verifies the allowlist and creation logic for partitions.
 */

// Load stubs
require_once __DIR__ . '/../Helpers/GlobalStubs.php';

// Load the logic
require_once __DIR__ . '/../../functions.php';

describe('Syslog Partitioning Integration', function () {
    beforeEach(function () {
        $GLOBALS['syslog_db_calls'] = [];
        $GLOBALS['syslog_test_config'] = [];
    });

    test('syslog_partition_table_allowed() only allows known tables', function () {
        expect(syslog_partition_table_allowed('syslog'))->toBeTrue();
        expect(syslog_partition_table_allowed('syslog_removed'))->toBeTrue();
        
        expect(syslog_partition_table_allowed('users'))->toBeFalse();
        expect(syslog_partition_table_allowed('syslog; DROP TABLE syslog'))->toBeFalse();
        expect(syslog_partition_table_allowed(''))->toBeFalse();
    });

    test('syslog_partition_create() returns false for disallowed tables', function () {
        // This should return early without doing anything
        $result = syslog_partition_create('invalid_table');
        expect($result)->toBeFalse();
        expect($GLOBALS['syslog_db_calls'])->toBeEmpty();
    });

    test('syslog_partition_create() uses prepared statements and locking', function () {
        global $syslogdb_default;
        $syslogdb_default = 'cacti_syslog';

        // Mock exists check to return false (partition does not exist)
        // Since GlobalStubs returns empty array, it will think it doesn't exist.
        
        // Mock GET_LOCK to return 1
        $GLOBALS['syslog_db_results']['fetch_cell_prepared'] = 1;

        syslog_partition_create('syslog');

        // Should have called:
        // 1. fetch_row_prepared (exists check)
        // 2. fetch_cell_prepared (GET_LOCK)
        // 3. fetch_row_prepared (double-checked locking re-check)
        // 4. execute_prepared (ALTER TABLE)
        // 5. fetch_cell_prepared (RELEASE_LOCK)
        
        expect($GLOBALS['syslog_db_calls'])->toHaveCount(5);
        expect($GLOBALS['syslog_db_calls'][0]['method'])->toBe('fetch_row_prepared');
        expect($GLOBALS['syslog_db_calls'][1]['method'])->toBe('fetch_cell_prepared');
        expect($GLOBALS['syslog_db_calls'][1]['sql'])->toContain('GET_LOCK');
        
        expect($GLOBALS['syslog_db_calls'][2]['method'])->toBe('fetch_row_prepared');
        expect($GLOBALS['syslog_db_calls'][3]['method'])->toBe('execute_prepared');
        expect($GLOBALS['syslog_db_calls'][3]['sql'])->toContain('ALTER TABLE');
        
        expect($GLOBALS['syslog_db_calls'][4]['method'])->toBe('fetch_cell_prepared');
        expect($GLOBALS['syslog_db_calls'][4]['sql'])->toContain('RELEASE_LOCK');
    });
});
