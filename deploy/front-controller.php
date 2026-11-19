<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$release = realpath(__DIR__.'/../canadamb-app/current');

if ($release === false || ! is_file($release.'/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 120');
    header('Cache-Control: no-store');
    exit('CanadaMB is temporarily unavailable.');
}

if (is_file($maintenance = $release.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $release.'/vendor/autoload.php';

$app = require_once $release.'/bootstrap/app.php';
$app->handleRequest(Request::capture());
