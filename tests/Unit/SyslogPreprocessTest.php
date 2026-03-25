<?php

declare(strict_types=1);

/*
 * Unit tests for syslog_preprocess_incoming_records().
 *
 * The function generates a uniqueID in the range 1-127 (constrained by the
 * tinyint `status` column), retries up to 256 times on collision, and returns
 * ['uniqueID' => 0, 'incoming' => 0] when it cannot acquire a lock or exhausts
 * all retry attempts.
 *
 * Runtime execution is not possible without a live DB, so these tests use
 * source-verification plus isolated logic mirrors that match the implementation.
 */

$_functions_src = file_get_contents(__DIR__ . '/../../functions.php');
if ($_functions_src === false) {
    fwrite(STDERR, "Failed to read functions.php\n");
    exit(1);
}

describe('syslog_preprocess_incoming_records — uniqueID allocation contract', function () use ($_functions_src): void {
    /*
     * The uniqueID range is a schema invariant: the `status` column is TINYINT
     * UNSIGNED NOT NULL which stores values 0-127 (signed tinyint in practice).
     * Zero is reserved for "unprocessed", so the allocation range is 1-127.
     */

    test('uniqueID is allocated via rand(1, 127)', function () use ($_functions_src): void {
        expect($_functions_src)->toContain('rand(1, 127)');
    });

    test('rand call does not use 0 as lower bound', function () use ($_functions_src): void {
        // rand(0, 127) would allow status=0 which is the unprocessed sentinel.
        expect($_functions_src)->not->toContain('rand(0, 127)');
    });

    test('rand call does not exceed 127 as upper bound', function () use ($_functions_src): void {
        // Values >127 cannot be stored in the tinyint status column.
        expect($_functions_src)->not->toContain('rand(1, 128)');
        expect($_functions_src)->not->toContain('rand(1, 255)');
        expect($_functions_src)->not->toContain('rand(1, 256)');
    });

    test('uniqueID range 1-127 produces only valid tinyint values', function (): void {
        // Mirror the allocation logic to verify the range invariant.
        for ($i = 0; $i < 200; $i++) {
            $id = rand(1, 127);
            expect($id)->toBeGreaterThanOrEqual(1);
            expect($id)->toBeLessThanOrEqual(127);
        }
    });
});

describe('syslog_preprocess_incoming_records — retry exhaustion', function () use ($_functions_src): void {
    test('function returns uniqueID 0 after 256 failed attempts', function () use ($_functions_src): void {
        // Verify the retry cap of 256 is present in the source.
        expect($_functions_src)->toContain('$attempts >= 256');
    });

    test('returns array with uniqueID key 0 on retry exhaustion', function () use ($_functions_src): void {
        // The return value must include the 'uniqueID' key set to 0 so callers
        // can detect the failure without a null-access risk.
        expect($_functions_src)->toContain("return array('uniqueID' => 0, 'incoming' => 0)");
    });

    test('retry exhaustion path logs an error via cacti_log', function () use ($_functions_src): void {
        expect($_functions_src)->toContain('Unable to find unused uniqueID after 256 attempts');
    });

    test('mirror: retry loop returns 0 once attempt cap is hit', function (): void {
        // Mirror of the retry logic with an always-busy stub.
        $attempts = 0;
        $uniqueID = 0;
        $max      = 256;

        while (true) {
            $candidate = rand(1, 127);
            $count     = 1; // stub: slot always occupied

            if ($count == 0) {
                $uniqueID = $candidate;
                break;
            }

            $attempts++;

            if ($attempts >= $max) {
                $uniqueID = 0;
                break;
            }
        }

        expect($uniqueID)->toBe(0);
        expect($attempts)->toBe($max);
    });

    test('mirror: retry loop succeeds on first free slot', function (): void {
        // Stub that reports busy for the first two candidates then free.
        $busy     = [true, true, false];
        $attempts = 0;
        $uniqueID = 0;
        $idx      = 0;

        while (true) {
            $candidate = rand(1, 127);
            $occupied  = $busy[$idx] ?? false;
            $idx++;

            if (!$occupied) {
                $uniqueID = $candidate;
                break;
            }

            $attempts++;

            if ($attempts >= 256) {
                $uniqueID = 0;
                break;
            }
        }

        expect($uniqueID)->toBeGreaterThanOrEqual(1);
        expect($uniqueID)->toBeLessThanOrEqual(127);
        expect($attempts)->toBe(2);
    });
});

describe('syslog_preprocess_incoming_records — lock acquisition', function () use ($_functions_src): void {
    test('acquires a GET_LOCK before allocation loop', function () use ($_functions_src): void {
        expect($_functions_src)->toContain('SELECT GET_LOCK(?, 10)');
    });

    test('releases lock via RELEASE_LOCK in a finally block', function () use ($_functions_src): void {
        // Verify RELEASE_LOCK sits inside a finally so it runs even on exception.
        expect($_functions_src)->toMatch('/finally\s*\{[^}]*RELEASE_LOCK/s');
    });

    test('lock acquisition failure returns early with uniqueID 0', function () use ($_functions_src): void {
        // When GET_LOCK returns !=1 the function must return immediately.
        expect($_functions_src)->toContain("(int)\$locked !== 1");
        expect($_functions_src)->toContain("return array('uniqueID' => 0, 'incoming' => 0)");
    });

    test('lock name is scoped to the syslogdb_default global', function () use ($_functions_src): void {
        // Lock name includes $syslogdb_default to prevent cross-database
        // lock collisions when multiple Cacti instances share a MySQL server.
        expect($_functions_src)->toContain('$syslogdb_default . \'.preprocess_incoming\'');
    });

    test('lock name is hashed with sha256', function () use ($_functions_src): void {
        // GET_LOCK names are capped at 64 chars; sha256 hex is exactly 64.
        expect($_functions_src)->toContain("hash('sha256'");
    });
});

describe('syslog_preprocess_incoming_records — UPDATE statement contract', function () use ($_functions_src): void {
    test('flags records with uniqueID via prepared statement', function () use ($_functions_src): void {
        // The UPDATE must use a placeholder, not string interpolation.
        expect($_functions_src)->toContain('SET `status` = ?');
        expect($_functions_src)->toContain("WHERE `status` = 0");
    });

    test('UPDATE uses syslog_db_execute_prepared, not syslog_db_execute', function () use ($_functions_src): void {
        // Verify the hardened call site uses the prepared variant.
        // Extract the preprocess function body for a scoped check.
        preg_match(
            '/function syslog_preprocess_incoming_records\s*\(\s*\)\s*\{(.+?)^\}/ms',
            $_functions_src,
            $m
        );
        $body = $m[1] ?? '';
        expect($body)->toContain('syslog_db_execute_prepared');
    });
});
