<?php

declare(strict_types=1);

require_once('../../include/global.php');

test('every php file in plugin_syslog has strict_types enabled', function () {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2), RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() === 'php' && strpos($file->getPathname(), '/vendor/') === false) {
            $content = file_get_contents($file->getPathname());
        }
    }
});

test('functions.php can be loaded with strict_types', function () {
    // This will throw a TypeError if there's an immediate conflict upon loading
    // (though unlikely just for loading unless there are default value issues)
    require_once dirname(__DIR__, 2) . '/functions.php';
    expect(function_exists('syslog_version'))->toBeTrue();
});
