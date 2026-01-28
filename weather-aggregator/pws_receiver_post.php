<?php
/**
 * PWS POST Receiver - Weather Aggregator
 *
 * Handles POST requests from PWS on port 8000, path /data/report/
 * Converts POST body to GET parameters and forwards to pws_receiver.php
 *
 * Modified: 2026-01-28 - Initial creation
 */

// Read POST body (URL-encoded parameters)
$postBody = file_get_contents('php://input');

if (empty($postBody)) {
    http_response_code(400);
    echo "error: empty body";
    exit;
}

// Parse URL-encoded body into array
parse_str($postBody, $params);

// Set $_GET from parsed params (pws_receiver.php uses $_GET)
$_GET = $params;

// Include main receiver
require_once __DIR__ . '/pws_receiver.php';
