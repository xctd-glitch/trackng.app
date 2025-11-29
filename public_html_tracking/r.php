<?php

/**
 * Redirect Service Entrypoint (Tracking Domain)
 * Public redirect service untuk end-users
 *
 * This file runs on tracking domain (t.qvtrk.com) only
 * Rate limited for public access
 *
 * Example Usage:
 * https://t.qvtrk.com/r.php?click_id=ABC123&user_lp=campaign1
 */

declare(strict_types=1);

// Load bootstrap - portable path untuk shared hosting
// dirname(__DIR__) = /home/username (parent dari public_html_tracking)
require_once '/home/user/trackng.app/srp/src/bootstrap.php';

use SRP\Controllers\LandingController;

// Apply tracking domain security headers (minimal, untuk redirect cepat)
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Handle redirect
LandingController::route();
