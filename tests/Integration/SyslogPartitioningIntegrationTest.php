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
    });
});
