<?php
/**
 * TelePulse - Standalone Cron Monitoring Script
 * Run this script via cPanel Cron Jobs every 1 or 5 minutes.
 */

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
$result = run_monitoring_cycle($config);
echo json_encode($result, JSON_PRETTY_PRINT);
