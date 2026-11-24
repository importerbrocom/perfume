<?php

/*
 * Front controller for the web folder on cPanel (e.g. ~/perfume.nokkoo.in).
 * The Laravel app itself lives outside the web folder in ~/pmshop/current (a link to the active release),
 * so code, .env and logs can never be downloaded. server/platform.sh writes this file with the right path.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$app_dir = '__PM_APP_DIR__';

if (file_exists($maintenance = $app_dir.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app_dir.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $app_dir.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
