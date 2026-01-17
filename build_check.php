<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function ($e) {
    fwrite(STDERR, "BUILD FAILED: " . $e->getMessage() . PHP_EOL);
    exit(1);
});

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/index.php';

echo "BUILD OK\n";
