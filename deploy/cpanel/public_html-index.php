<?php

/*
 * CarYard front controller for "method C" in docs/deploy-cpanel.md: the app lives in ~/lotlink (outside the web
 * root) and the contents of lotlink/public were copied into ~/public_html. Copy this file to
 * ~/public_html/index.php (replacing the one copied from lotlink/public) and set MEDIA_ROOT in .env to
 * /home/<cpanel user>/public_html/media. If your folder isn't ~/lotlink, change $app below.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$root = dirname(__DIR__).'/lotlink';

if (file_exists($maintenance = $root.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $root.'/bootstrap/app.php';

// public_html is the web root: built assets, images (media/) and service worker are served from here.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
