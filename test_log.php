<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

file_put_contents(__DIR__ . '/error.log', "Test log entry: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
trigger_error("This is a test error", E_USER_ERROR);

echo "Test completed";
?>