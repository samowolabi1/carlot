<?php

/*
 * CarYard front controller for "method C" in docs/deploy-cpanel.md: the app lives in ~/caryard (outside the web
 * root) and the contents of caryard/public were copied into ~/public_html. Copy this file to
 * ~/public_html/index.php (replacing the one copied from caryard/public) and set MEDIA_ROOT in .env to
 * /home/<cpanel user>/public_html/media. If your folder isn't ~/caryard, change $app below.
 */

use App\Domain\System\AppVersion;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$root = dirname(__DIR__).'/caryard';

if (file_exists($maintenance = $root.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $root.'/vendor/autoload.php';

// A new package was uploaded and the cron job hasn't finished updating yet: a short "updating" page, not errors.
if (AppVersion::pending($root)) {
    AppVersion::respondUpdating();
}

/** @var Application $app */
$app = require_once $root.'/bootstrap/app.php';

// public_html is the web root: built assets, images (media/) and service worker are served from here.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
