<?php

declare(strict_types=1);

/*
 * Unit tests for syslog_build_match_filter().
 *
 * The function returns ['sql' => string, 'params' => array] and is the
 * central WHERE-clause builder used by removal, alert, and report code
 * paths. These tests cover every match type, the sql trust boundary,
 * edge inputs, and special characters.
 */

require_once __DIR__ . '/../Helpers/GlobalStubs.php';
require_once __DIR__ . '/../../functions.php';

beforeEach(function (): void {
    $GLOBALS['syslogdb_default'] = 'cacti_syslog';
});

describe('syslog_build_match_filter — string equality types', function (): void {
    test('facility type: plain column uses equality placeholder', function (): void {
        $r = syslog_build_match_filter('facility', 'local0');
        expect($r['sql'])->toBe('facility = ?');
        expect($r['params'])->toBe(['local0']);
    });

    test('facility type: facility_id column uses subquery', function (): void {
        $r = syslog_build_match_filter('facility', 'local0', 'facility_id');
        expect($r['sql'])->toContain('facility_id IN (SELECT DISTINCT facility_id FROM');
        expect($r['sql'])->toContain('syslog_facilities');
        expect($r['sql'])->toContain('WHERE facility = ?');
        expect($r['params'])->toBe(['local0']);
    });

    test('host type: plain column uses equality placeholder', function (): void {
        $r = syslog_build_match_filter('host', '10.0.0.1');
        expect($r['sql'])->toBe('host = ?');
        expect($r['params'])->toBe(['10.0.0.1']);
    });

    test('host type: host_id column uses subquery', function (): void {
        $r = syslog_build_match_filter('host', '10.0.0.1', 'host_id');
        expect($r['sql'])->toContain('host_id IN (SELECT DISTINCT host_id FROM');
        expect($r['sql'])->toContain('syslog_hosts');
        expect($r['sql'])->toContain('WHERE host = ?');
        expect($r['params'])->toBe(['10.0.0.1']);
    });

    test('program type: plain column uses equality placeholder', function (): void {
        $r = syslog_build_match_filter('program', 'sshd');
        expect($r['sql'])->toBe('program = ?');
        expect($r['params'])->toBe(['sshd']);
    });

    test('program type: program_id column uses subquery', function (): void {
        $r = syslog_build_match_filter('program', 'sshd', 'program_id');
        expect($r['sql'])->toContain('program_id IN (SELECT DISTINCT program_id FROM');
        expect($r['sql'])->toContain('syslog_programs');
        expect($r['sql'])->toContain('WHERE program = ?');
        expect($r['params'])->toBe(['sshd']);
    });
});

describe('syslog_build_match_filter — substring match types', function (): void {
    test('messageb (starts with): appends trailing wildcard', function (): void {
        $r = syslog_build_match_filter('messageb', 'kernel:', 'message');
        expect($r['sql'])->toBe('message LIKE ?');
        expect($r['params'])->toBe(['kernel:%']);
    });

    test('messagec (contains): wraps value in wildcards', function (): void {
        $r = syslog_build_match_filter('messagec', 'error', 'message');
        expect($r['sql'])->toBe('message LIKE ?');
        expect($r['params'])->toBe(['%error%']);
    });

    test('messagee (ends with): prepends leading wildcard', function (): void {
        $r = syslog_build_match_filter('messagee', 'failed', 'message');
        expect($r['sql'])->toBe('message LIKE ?');
        expect($r['params'])->toBe(['%failed']);
    });

    test('messageb uses default column when none supplied', function (): void {
        $r = syslog_build_match_filter('messageb', 'start');
        expect($r['sql'])->toBe('message LIKE ?');
    });

    test('messagec uses explicit column override', function (): void {
        $r = syslog_build_match_filter('messagec', 'needle', 'logmessage');
        expect($r['sql'])->toBe('logmessage LIKE ?');
        expect($r['params'])->toBe(['%needle%']);
    });

    test('messagee uses explicit column override', function (): void {
        $r = syslog_build_match_filter('messagee', 'tail', 'logtext');
        expect($r['sql'])->toBe('logtext LIKE ?');
        expect($r['params'])->toBe(['%tail']);
    });
});

describe('syslog_build_match_filter — sql trust boundary', function (): void {
    /*
     * The 'sql' type is intentional: only Cacti administrators with console
     * access configure removal/alert rules, so arbitrary expressions are
     * permitted. The contract is that the raw value is wrapped in parens and
     * no params are bound.
     */

    test('sql type: wraps raw expression in parens, no bound params', function (): void {
        $r = syslog_build_match_filter('sql', 'host_id = 42 AND facility_id < 10');
        expect($r['sql'])->toBe('(host_id = 42 AND facility_id < 10)');
        expect($r['params'])->toBe([]);
    });

    test('sql type: passes value through without escaping', function (): void {
        $expr = "message LIKE '%critical%'";
        $r    = syslog_build_match_filter('sql', $expr);
        expect($r['sql'])->toBe('(' . $expr . ')');
        expect($r['params'])->toBe([]);
    });

    test('sql type: empty expression wraps empty parens', function (): void {
        $r = syslog_build_match_filter('sql', '');
        expect($r['sql'])->toBe('()');
        expect($r['params'])->toBe([]);
    });
});

describe('syslog_build_match_filter — empty and null-like values', function (): void {
    test('messageb with empty value produces trailing-wildcard-only param', function (): void {
        $r = syslog_build_match_filter('messageb', '');
        expect($r['params'])->toBe(['%']);
    });

    test('messagec with empty value produces double-wildcard param', function (): void {
        $r = syslog_build_match_filter('messagec', '');
        expect($r['params'])->toBe(['%%']);
    });

    test('messagee with empty value produces leading-wildcard-only param', function (): void {
        $r = syslog_build_match_filter('messagee', '');
        expect($r['params'])->toBe(['%']);
    });

    test('host type with empty value still binds placeholder', function (): void {
        $r = syslog_build_match_filter('host', '');
        expect($r['sql'])->toBe('host = ?');
        expect($r['params'])->toBe(['']);
    });

    test('unknown type returns empty sql and empty params', function (): void {
        $r = syslog_build_match_filter('nonexistent', 'value');
        expect($r['sql'])->toBe('');
        expect($r['params'])->toBe([]);
    });
});

describe('syslog_build_match_filter — special characters in match values', function (): void {
    test('SQL metacharacters in messagec value are passed as literal param (not interpolated)', function (): void {
        $payload = "' OR '1'='1";
        $r       = syslog_build_match_filter('messagec', $payload);
        // Value must appear verbatim in the param, not in the SQL fragment.
        expect($r['sql'])->not->toContain($payload);
        expect($r['params'][0])->toBe('%' . $payload . '%');
    });

    test('backslash in messageb value is passed to param intact', function (): void {
        $r = syslog_build_match_filter('messageb', 'C:\\Windows\\');
        expect($r['params'][0])->toBe('C:\\Windows\\%');
    });

    test('percent sign in messagec value is preserved in param', function (): void {
        $r = syslog_build_match_filter('messagec', '50% done');
        expect($r['params'][0])->toBe('%50% done%');
    });

    test('underscore in messagee value is preserved in param', function (): void {
        $r = syslog_build_match_filter('messagee', 'auth_log');
        expect($r['params'][0])->toBe('%auth_log');
    });

    test('host type with injection attempt binds raw value as param', function (): void {
        $payload = "1 OR 1=1--";
        $r       = syslog_build_match_filter('host', $payload);
        expect($r['sql'])->toBe('host = ?');
        expect($r['params'])->toBe([$payload]);
    });

    test('subquery path: facility_id with special characters binds raw value as param', function (): void {
        $payload = "'; DROP TABLE syslog;--";
        $r       = syslog_build_match_filter('facility', $payload, 'facility_id');
        expect($r['sql'])->toContain('WHERE facility = ?');
        expect($r['params'])->toBe([$payload]);
    });
});
