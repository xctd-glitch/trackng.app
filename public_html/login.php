<?php
declare(strict_types=1);

// Absolute path untuk production
require_once '/home/user/trackng.app/srp/src/bootstrap.php';

use SRP\Controllers\AuthController;

AuthController::login();
