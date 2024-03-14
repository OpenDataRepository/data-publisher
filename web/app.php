<?php

umask(0000);

/** At the top of the file */

if (
    is_readable(__DIR__.'/../0_MAINTENANCE')
    // && !in_array(@$_SERVER['REMOTE_ADDR'], array(
        // '127.0.0.1',
        // '216.220.243.136',
        // '::1',
    // ))
) {
    http_response_code(503);
    include "./maintenance/index.php";
    die();
}

use Symfony\Component\HttpFoundation\Request;
/**
 * @var Composer\Autoload\ClassLoader
 */
$loader = require __DIR__.'/../app/autoload.php';
require_once __DIR__.'/../app/bootstrap.php.cache';
$kernel = new AppKernel('prod', false);
$kernel->loadClassCache();

// When using the HttpCache, you need to call the method in your front controller instead of relying on the configuration parameter
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
