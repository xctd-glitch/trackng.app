<?php

/**
 * Decision API Entrypoint (Tracking Domain)
 * Internal Decision API untuk routing decisions
 *
 * This file runs on tracking domain (qvtrk.com) only
 * Protected by API key authentication
 */

declare(strict_types=1);

// Load bootstrap - portable path untuk shared hosting
// dirname(__DIR__) = /home/username (parent dari public_html_tracking)
require_once '/home/user/trackng.app/srp/src/bootstrap.php';

use SRP\Controllers\DecisionController;
use SRP\Middleware\SecurityHeaders;

// Apply tracking domain security headers
SecurityHeaders::applyTrackingHeaders();

// Handle decision request
DecisionController::handleDecision();
