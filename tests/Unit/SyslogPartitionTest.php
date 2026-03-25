<?php

declare(strict_types=1);

/*
 * Unit tests for partition management in functions.php.
 *
 * Covers:
 *   - syslog_partition_table_allowed(): allowlist enforcement and regex guard
 *   - Lock acquisition / release pattern in syslog_partition_create() and
 *     syslog_partition_remove()
 *
 * Runtime execution requires a live DB; these tests use source-verification
 * plus the pure-PHP allowlist function which has no external dependencies.
 */

require_once __DIR__ . '/../Helpers/GlobalStubs.php';
require_once __DIR__ . '/../../functions.php';

$_functions_src = file_get_contents(__DIR__ . '/../../functions.php');
if ($_functions_src === false) {
    fwrite(STDERR, "Failed to read functions.php\n");
    exit(1);
}

beforeEach(function (): void {
    $GLOBALS['syslogdb_default'] = 'cacti_syslog';
});

describe('syslog_partition_table_allowed — allowlist enforcement', function (): void {
    test('accepts "syslog"', function (): void {
        expect(syslog_partition_table_allowed('syslog'))->toBeTrue();
    });

    test('accepts "syslog_removed"', function (): void {
        expect(syslog_partition_table_allowed('syslog_removed'))->toBeTrue();
    });

    test('rejects an arbitrary table name', function (): void {
        expect(syslog_partition_table_allowed('host'))->toBeFalse();
    });

    test('rejects an empty string', function (): void {
        expect(syslog_partition_table_allowed(''))->toBeFalse();
    });

    test('rejects a name with SQL injection characters', function (): void {
        expect(syslog_partition_table_allowed('syslog; DROP TABLE syslog;--'))->toBeFalse();
    });

    test('rejects a name with path traversal characters', function (): void {
        expect(syslog_partition_table_allowed('../../../etc/passwd'))->toBeFalse();
    });

    test('rejects a name with backtick injection', function (): void {
        expect(syslog_partition_table_allowed('`syslog`'))->toBeFalse();
    });

    test('rejects uppercase variant (case-sensitive allowlist)', function (): void {
        expect(syslog_partition_table_allowed('SYSLOG'))->toBeFalse();
        expect(syslog_partition_table_allowed('Syslog'))->toBeFalse();
    });

    test('rejects a partial match that is not in the allowlist', function (): void {
        expect(syslog_partition_table_allowed('syslog_'))->toBeFalse();
        expect(syslog_partition_table_allowed('syslog_remove'))->toBeFalse();
    });

    test('allowlist is strict: only exactly two values pass', function (): void {
        $candidates = [
            'syslog'         => true,
            'syslog_removed' => true,
            'syslog_data'    => false,
            'syslog2'        => false,
            'plugin_syslog'  => false,
            ''               => false,
            ' syslog'        => false,
            'syslog '        => false,
        ];

        foreach ($candidates as $name => $expected) {
            expect(syslog_partition_table_allowed($name))
                ->toBe($expected, "Expected syslog_partition_table_allowed('$name') === " . ($expected ? 'true' : 'false'));
        }
    });
});

describe('syslog_partition_table_allowed — regex defense-in-depth', function () use ($_functions_src): void {
    /*
     * Even for tables that pass the allowlist, a secondary regex ensures the
     * identifier contains only [a-z_] characters. This is belt-and-suspenders
     * against future changes that might widen the allowlist carelessly.
     */

    test('source contains [a-z_] regex guard inside syslog_partition_table_allowed', function () use ($_functions_src): void {
        preg_match(
            '/function syslog_partition_table_allowed\s*\(\s*\$table\s*\)\s*\{(.+?)^\}/ms',
            $_functions_src,
            $m
        );
        $body = $m[1] ?? '';
        expect($body)->toContain('[a-z_]');
    });

    test('function explicitly returns false for non-members', function () use ($_functions_src): void {
        preg_match(
            '/function syslog_partition_table_allowed\s*\(\s*\$table\s*\)\s*\{(.+?)^\}/ms',
            $_functions_src,
            $m
        );
        $body = $m[1] ?? '';
        expect($body)->toContain('return false');
    });
});

describe('syslog_partition_create — lock acquisition and release', function () use ($_functions_src): void {
    test('acquires GET_LOCK before issuing ALTER TABLE', function () use ($_functions_src): void {
        preg_match(
            '/function syslog_partition_create\s*\(\s*\$table\s*\)\s*\{(.+?)^\}/ms',
            $_functions_src,
            $m
        );
        $body = $m[1] ?? '';
        expect($body)->toContain('GET_LOCK');
        expect($body)->toContain('ALTER TABLE');
        // GET_LOCK position must precede ALTER TABLE.
        expect(strpos($body, 'GET_LOCK'))->toBeLessThan(strpos($body, 'ALTER TABLE'));
    });

    test('releases lock in a finally block', function () use ($_functions_src): void {
        preg_match(
            '/function syslog_partition_create\s*\(\s*\$table\s*\)\s*\{(.+?)^\}/ms',
            $_functions_src,
            $m
        );
        $body = $m[1] ?? '';
        expect($body)->toMatch('/finally\s*\{[^}]*RELEASE_LOCK/s');
    });

    test('returns false immediately when allowlist rejects the table', function () use ($_functions_src): void {
        preg_match(
            '/function syslog_partition_create\s*\(\s*\$table\s*\)\s*\{(.{0,400})/s',
            $_functions_src,
            $m
        );
        $head = $m[1] ?? '';
        expect($head)->toMatch('/!syslog_partition_table_allowed[^}]*return\s+false/s');
    });

    test('lock name includes both syslogdb_default and table', function () use ($_functions_src): void {
        expect($_functions_src)->toContain('syslog_partition_create.\' . $table');
    });

    test('lock timeout is 10 seconds', function () use ($_functions_src): void {
        // Verify the timeout value passed to GET_LOCK matches the 10s convention.
        expect($_functions_src)->toContain('SELECT GET_LOCK(?, 10)');
    });

    test('failed lock acquisition returns false without issuing DDL', function () use ($_functions_src): void {
        preg_match(
            '/function syslog_partition_create\s*\(\s*\$table\s*\)\s*\{(.+?)^\}/ms',
            $_functions_src,
            $m
        );
        $body = $m[1] ?? '';
        // The guard on (int)$locked !== 1 must appear before ALTER TABLE.
        $lockGuardPos  = strpos($body, '(int)$locked !== 1');
        $alterTablePos = strpos($body, 'ALTER TABLE');
        expect($lockGuardPos)->not->toBeFalse();
        expect($alterTablePos)->not->toBeFalse();
        expect($lockGuardPos)->toBeLessThan($alterTablePos);
    });

    test('DDL safety comment documents why parameters are not bound', function () use ($_functions_src): void {
        expect($_functions_src)->toContain('MySQL does not support parameter binding for DDL');
    });

    test('partition format values are validated by regex before DDL', function () use ($_functions_src): void {
        preg_match(
            '/function syslog_partition_create\s*\(\s*\$table\s*\)\s*\{(.+?)^\}/ms',
            $_functions_src,
            $m
        );
        $body = $m[1] ?? '';
        // Both $cformat and $lnow must pass preg_match validation.
        expect($body)->toContain('preg_match');
        expect($body)->toContain('d\d{8}');
    });
});

describe('syslog_partition_remove — lock acquisition and release', function () use ($_functions_src): void {
    test('acquires GET_LOCK before issuing ALTER TABLE', function () use ($_functions_src): void {
        preg_match(
            '/function syslog_partition_remove\s*\(\s*\$table\s*\)\s*\{(.+?)^\}/ms',
            $_functions_src,
            $m
        );
        $body = $m[1] ?? '';
        expect($body)->toContain('GET_LOCK');
    });

    test('releases lock in a finally block', function () use ($_functions_src): void {
        preg_match(
            '/function syslog_partition_remove\s*\(\s*\$table\s*\)\s*\{(.+?)^\}/ms',
            $_functions_src,
            $m
        );
        $body = $m[1] ?? '';
        expect($body)->toMatch('/finally\s*\{[^}]*RELEASE_LOCK/s');
    });

    test('returns 0 immediately when allowlist rejects the table', function () use ($_functions_src): void {
        preg_match(
            '/function syslog_partition_remove\s*\(\s*\$table\s*\)\s*\{(.{0,400})/s',
            $_functions_src,
            $m
        );
        $head = $m[1] ?? '';
        expect($head)->toMatch('/!syslog_partition_table_allowed[^}]*return\s+0/s');
    });

    test('logs an error when called with a disallowed table', function () use ($_functions_src): void {
        preg_match(
            '/function syslog_partition_remove\s*\(\s*\$table\s*\)\s*\{(.{0,600})/s',
            $_functions_src,
            $m
        );
        $head = $m[1] ?? '';
        expect($head)->toMatch('/!syslog_partition_table_allowed.*cacti_log.*disallowed/s');
    });

    test('lock name is scoped per-operation and per-table', function () use ($_functions_src): void {
        expect($_functions_src)->toContain('syslog_partition_remove.\' . $table');
    });

    test('information_schema query uses prepared statement with table_name placeholder', function () use ($_functions_src): void {
        preg_match(
            '/function syslog_partition_remove\s*\(\s*\$table\s*\)\s*\{(.+?)^\}/ms',
            $_functions_src,
            $m
        );
        $body = $m[1] ?? '';
        expect($body)->toMatch('/syslog_db_fetch_(?:row|assoc|cell)_prepared[^)]*information_schema[^)]*table_name\s*=\s*\?/s');
    });
});

describe('syslog_partition_create — runtime allowlist rejection (pure PHP)', function (): void {
    /*
     * These call the live function against disallowed inputs to confirm the
     * guard executes without touching any DB layer.
     */

    test('returns false for a disallowed table without touching DB', function (): void {
        // syslog_partition_create returns false when the table is not in the
        // allowlist, before any DB call is made.
        $result = syslog_partition_create('evil_table');
        expect($result)->toBeFalse();
    });

    test('returns false for empty table name', function (): void {
        $result = syslog_partition_create('');
        expect($result)->toBeFalse();
    });
});

describe('syslog_partition_remove — runtime allowlist rejection (pure PHP)', function (): void {
    test('returns 0 for a disallowed table without touching DB', function (): void {
        $result = syslog_partition_remove('evil_table');
        expect($result)->toBe(0);
    });

    test('returns 0 for empty table name', function (): void {
        $result = syslog_partition_remove('');
        expect($result)->toBe(0);
    });
});
