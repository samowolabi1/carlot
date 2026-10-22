<?php

use App\Domain\System\AppVersion;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// A new cPanel package was uploaded and the cron job hasn't finished updating yet: a short "updating" page, not errors.
if (AppVersion::pending(dirname(__DIR__))) {
    AppVersion::respondUpdating();
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
