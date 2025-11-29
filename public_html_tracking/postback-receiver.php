<?php

/**
 * Postback Receiver Entrypoint (Tracking Domain)
 * Receives incoming postback dari affiliate networks
 *
 * This file runs on tracking domain (postback.qvtrk.com) only
 * Protected by API key atau IP whitelist
 *
 * Example incoming postback:
 * https://postback.qvtrk.com/postback-receiver.php?status=confirmed&country=US&traffic_type=WAP&payout=1.50&click_id=ABC123&network=example
 */

declare(strict_types=1);

// Load bootstrap - portable path untuk shared hosting
// dirname(__DIR__) = /home/username (parent dari public_html_tracking)
require_once '/home/user/trackng.app/srp/src/bootstrap.php';

use SRP\Config\Database;
use SRP\Models\Validator;
use SRP\Middleware\SecurityHeaders;

// Apply tracking domain security headers
SecurityHeaders::applyTrackingHeaders();

// Only accept GET and POST
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get client IP
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Trust CloudFlare headers if configured
if (!empty($_ENV['TRUST_CF_HEADERS']) && $_ENV['TRUST_CF_HEADERS'] === 'true') {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
}

// Log request
error_log("Postback received from IP: {$clientIp}");

try {
    // Parse input
    $input = ($method === 'POST') ? $_POST : $_GET;

    // Sanitize input
    $status = Validator::sanitizeString($input['status'] ?? 'unknown', 50);
    $country = Validator::sanitizeString($input['country'] ?? $input['country_code'] ?? 'XX', 10);
    $country = strtoupper($country);
    $trafficType = Validator::sanitizeString($input['traffic_type'] ?? $input['device'] ?? 'unknown', 50);
    $payout = (float) ($input['payout'] ?? 0.00);
    $clickId = Validator::sanitizeString($input['click_id'] ?? $input['cid'] ?? '', 100);
    $network = Validator::sanitizeString($input['network'] ?? 'unknown', 100);

    // Validate required fields
    if ($status === '' || $country === '' || $trafficType === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
        exit;
    }

    // Validate payout
    if ($payout < 0 || $payout > 10000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid payout value']);
        exit;
    }

    // Store full query string untuk debugging
    $queryString = http_build_query($input);

    // Insert to database
    $stmt = Database::execute(
        'INSERT INTO postback_received (ts, status, country_code, traffic_type, payout, click_id, network, ip_address, query_string)
         VALUES (UNIX_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?)',
        [$status, $country, $trafficType, $payout, $clickId !== '' ? $clickId : null, $network, $clientIp, $queryString]
    );

    // Success response
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Postback received successfully',
        'id' => Database::getConnection()->lastInsertId(),
    ], JSON_THROW_ON_ERROR);

    error_log("Postback saved: Status={$status}, Country={$country}, Payout={$payout}");
} catch (\Throwable $e) {
    error_log('Postback receiver error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal server error']);
}

exit;
