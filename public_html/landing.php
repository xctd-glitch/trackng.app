<?php
declare(strict_types=1);

// Portable path untuk shared hosting: dirname(__DIR__) = /home/username
require_once '/home/user/trackng.app/srp/src/bootstrap.php';

use SRP\Controllers\LandingController;

// Check if this is a routing request (has click_id or user_lp parameter)
if (!empty($_GET['click_id']) || !empty($_GET['user_lp'])) {
    // Route traffic through SRP API
    LandingController::route();
} else {
    // Display landing page information
    LandingController::index();
}
