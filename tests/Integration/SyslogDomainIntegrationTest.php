<?php

declare(strict_types=1);

/*
 * Integration tests for syslog domain processing.
 *
 * This test verifies the hardening of the domain stripping loop.
 */

// Load stubs
require_once __DIR__ . '/../Helpers/GlobalStubs.php';

// Load the logic
require_once __DIR__ . '/../../functions.php';

describe('Syslog Domain Processing Integration', function () {
    beforeEach(function () {
        $GLOBALS['syslog_db_calls'] = [];
        $GLOBALS['syslog_test_config'] = [];
    });

    test('syslog_strip_incoming_domains() parameterizes UPDATE queries', function () {
        $uniqueID = 12345;
        global $syslogdb_default;
        $syslogdb_default = 'cacti_syslog';

        $GLOBALS['syslog_test_config']['syslog_domains'] = 'example.com,test.org';

        syslog_strip_incoming_domains($uniqueID);

        expect($GLOBALS['syslog_db_calls'])->toHaveCount(2);
        
        expect($GLOBALS['syslog_db_calls'][0]['method'])->toBe('execute_prepared');
        expect($GLOBALS['syslog_db_calls'][0]['params'])->toBe(['%example.com', 12345]);
        expect($GLOBALS['syslog_db_calls'][1]['params'])->toBe(['%test.org', 12345]);
        
        expect($GLOBALS['syslog_db_calls'][0]['sql'])->toContain('WHERE host LIKE ?');
        expect($GLOBALS['syslog_db_calls'][0]['sql'])->toContain('AND `status` = ?');
    });
});
