<?php
/**
 * TelePulse - Webhook Endpoint Entry Point
 */

require_once __DIR__ . '/functions.php';

$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Only POST requests allowed']);
    exit;
}

$raw_input = file_get_contents('php://input');
$update = json_decode($raw_input, true);

if (!$update) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
    exit;
}

$handled = handle_webhook_update($update, $config);

echo json_encode(['ok' => true, 'handled' => $handled]);
