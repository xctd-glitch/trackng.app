<?php

/**
 * External API Wrapper Entrypoint (Tracking Domain)
 * Public API wrapper untuk external hosting/domains
 *
 * This file runs on tracking domain (api.qvtrk.com) only
 * Protected by API key authentication
 * Rate limited for public access
 *
 * Example Usage:
 * GET: https://api.qvtrk.com/api-external.php?click_id=ABC123&country_code=US&user_agent=mobile&ip_address=1.2.3.4&api_key=YOUR_KEY
 * POST: https://api.qvtrk.com/api-external.php (with JSON body and X-API-Key header)
 */

declare(strict_types=1);

// Load bootstrap - portable path untuk shared hosting
// dirname(__DIR__) = /home/username (parent dari public_html_tracking)
require_once '/home/user/trackng.app/srp/src/bootstrap.php';

use SRP\Controllers\ExternalApiController;
use SRP\Middleware\SecurityHeaders;

// Apply tracking domain security headers
SecurityHeaders::applyTrackingHeaders();

// Handle external API request
ExternalApiController::handle();
