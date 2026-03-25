<?php

declare(strict_types=1);

/*
 * Integration tests for syslog removal rules.
 *
 * This test verifies that removal rules are correctly processed and parameterized.
 */

// Load stubs
require_once __DIR__ . '/../Helpers/GlobalStubs.php';

// Load the logic
require_once __DIR__ . '/../../functions.php';

describe('Syslog Removal Rules Integration', function () {
    beforeEach(function () {
        $GLOBALS['syslog_db_calls'] = [];
        $GLOBALS['syslog_db_results'] = [];
        $GLOBALS['syslog_test_config'] = [];
        $GLOBALS['syslogdb_default'] = 'cacti_syslog';
        $GLOBALS['syslog_incoming_config'] = [
            'facilityField' => 'facility_id',
            'priorityField' => 'priority_id',
            'programField'  => 'program',
            'textField'     => 'message',
            'dateField'     => 'logtime',
            'timeField'     => 'logtime',
            'hostField'     => 'host',
            'messageField'  => 'message'
        ];
    });

    test('syslog_manage_items() parameterizes facility removal', function () {
        // Mock removal rules
        $GLOBALS['syslog_db_results']['fetch_assoc'] = [
            [
                array(
                    'id' => 1,
                    'enabled' => 'on',
                    'type' => 'facility',
                    'method' => 'del',
                    'message' => "test' OR '1'='1"
                )
            ]
        ];

        syslog_manage_items('syslog', 'syslog_removed');

        // Check if any execute_prepared call contains the unescaped message
        $foundUnsafe = false;
        foreach ($GLOBALS['syslog_db_calls'] as $call) {
            if ($call['method'] === 'execute_prepared') {
                if (strpos($call['sql'], "test' OR '1'='1") !== false) {
                    $foundUnsafe = true;
                }
            }
        }

        // It should NOT be unsafe. If it's unsafe, it means it's not parameterized.
        // Currently it IS unsafe according to my reading of the code.
        expect($foundUnsafe)->toBeFalse();
    });

    test('syslog_manage_items() parameterizes host removal', function () {
        $GLOBALS['syslog_db_results']['fetch_assoc'] = [
            [
                array(
                    'id' => 2,
                    'enabled' => 'on',
                    'type' => 'host',
                    'method' => 'del',
                    'message' => "host' OR '1'='1"
                )
            ]
        ];

        syslog_manage_items('syslog', 'syslog_removed');

        $foundUnsafe = false;
        foreach ($GLOBALS['syslog_db_calls'] as $call) {
            if ($call['method'] === 'execute_prepared') {
                if (strpos($call['sql'], "host' OR '1'='1") !== false) {
                    $foundUnsafe = true;
                }
            }
        }
        expect($foundUnsafe)->toBeFalse();
    });

    test('syslog_manage_items() parameterizes message-based removal', function () {
        $GLOBALS['syslog_db_results']['fetch_assoc'] = [
            [
                array(
                    'id' => 3,
                    'enabled' => 'on',
                    'type' => 'messagec',
                    'method' => 'del',
                    'message' => "some'-- unsafe"
                )
            ]
        ];

        syslog_manage_items('syslog', 'syslog_removed');

        $foundUnsafe = false;
        foreach ($GLOBALS['syslog_db_calls'] as $call) {
            if ($call['method'] === 'execute_prepared') {
                if (strpos($call['sql'], "some'-- unsafe") !== false) {
                    $foundUnsafe = true;
                }
            }
        }
        expect($foundUnsafe)->toBeFalse();
    });

    test('syslog_remove_items() handles facility removal for syslog_incoming', function () {
        $uniqueID = 111;
        $GLOBALS['syslog_db_results']['fetch_assoc'] = [
            [
                array(
                    'id' => 4,
                    'name' => 'Test Rule',
                    'enabled' => 'on',
                    'type' => 'facility',
                    'method' => 'del',
                    'message' => 'local0'
                )
            ]
        ];

        syslog_remove_items('syslog_incoming', $uniqueID);

        // Check for delete call
        $foundDelete = false;
        foreach ($GLOBALS['syslog_db_calls'] as $call) {
            if ($call['method'] === 'execute_prepared' && strpos($call['sql'], 'DELETE') !== false) {
                if (in_array('local0', $call['params']) && in_array(111, $call['params'])) {
                    $foundDelete = true;
                }
            }
        }
        expect($foundDelete)->toBeTrue();
    });
});
