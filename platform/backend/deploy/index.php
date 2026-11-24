<?php

/*
 * Front controller for the web folder on cPanel (e.g. ~/perfume.nokkoo.in).
 * The Laravel app itself lives outside the web folder in ~/pmshop/current (a link to the active release),
 * so code, .env and logs can never be downloaded. server/platform.sh writes this file with the right path.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$link = '__PM_APP_DIR__';
// Follow the "current" link on every request. readlink() is not cached (realpath() is, for up to
// 2 minutes), so a release switch takes effect at once in long-running PHP processes (LiteSpeed).
$target = is_link($link) ? readlink($link) : false;
$app_dir = $target === false ? $link : ($target[0] === '/' ? $target : dirname($link).'/'.$target);

if (file_exists($maintenance = $app_dir.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app_dir.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $app_dir.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
