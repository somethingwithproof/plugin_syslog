<?php

declare(strict_types=1);

/*
 * Integration tests for syslog report XSS escaping.
 */

// Load stubs
require_once __DIR__ . '/../Helpers/GlobalStubs.php';

// Mock some dependencies
if (!function_exists('reports_load_format_file')) {
    function reports_load_format_file($file, &$output, &$tag, &$theme) { return true; }
}
if (!function_exists('mailer')) {
    function mailer($from, $to, $cc, $bcc, $reply_to, $subject, $html_message, $text_message) {
        $GLOBALS['syslog_sent_emails'][] = [
            'to' => $to,
            'subject' => $subject,
            'message' => $html_message ?: $text_message
        ];
    }
}

// Load the logic
require_once __DIR__ . '/../../functions.php';

describe('Syslog Report XSS Integration', function () {
    beforeEach(function () {
        $GLOBALS['config'] = ['base_path' => __DIR__];
        $GLOBALS['syslog_db_calls'] = [];
        $GLOBALS['syslog_db_results'] = [];
        $GLOBALS['syslog_test_config'] = [];
        $GLOBALS['syslog_test_config']['cron_interval'] = 300;
        $GLOBALS['syslog_sent_emails'] = [];
        $GLOBALS['syslogdb_default'] = 'cacti_syslog';
        $GLOBALS['forcer'] = true; // Force report to run
    });

    test('syslog_process_reports() escapes host and message in email content', function () {
        // Mock reports fetch
        $GLOBALS['syslog_db_results']['fetch_assoc'] = [
            [
                [
                    'id' => 1,
                    'name' => 'XSS Report',
                    'enabled' => 'on',
                    'type' => 'messagec',
                    'message' => 'test',
                    'lastsent' => 0,
                    'timespan' => 3600,
                    'timepart' => 0,
                    'email' => 'test@example.com',
                    'body' => 'Check this'
                ]
            ]
        ];

        // Mock items fetch (now uses fetch_assoc_prepared)
        $GLOBALS['syslog_db_results']['fetch_assoc_prepared'] = [
            [
                [
                    'host' => '<script>alert("host")</script>',
                    'priority_id' => 1,
                    'facility_id' => 1,
                    'message' => '"><img src=x onerror=alert(1)>',
                    'logtime' => '2026-03-23 10:00:00'
                ]
            ]
        ];
        
        syslog_process_reports();
        
        expect($GLOBALS['syslog_sent_emails'])->toHaveCount(1);
        $email = $GLOBALS['syslog_sent_emails'][0]['message'];
        
        // Host should be escaped
        expect($email)->toContain('&lt;script&gt;alert(&quot;host&quot;)&lt;/script&gt;');
        expect($email)->not->toContain('<script>alert("host")</script>');
        
        // Message should be escaped
        expect($email)->toContain('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;');
        expect($email)->not->toContain('"><img src=x onerror=alert(1)>');
    });
});
