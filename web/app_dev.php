<?php

umask(0000);
use Symfony\Component\HttpFoundation\Request;

// This check prevents access to debug front controllers that are deployed by accident to production servers.
// Feel free to remove this, extend it, or make something more sophisticated.
if (
    // isset($_SERVER['HTTP_CLIENT_IP'])
    // || isset($_SERVER['HTTP_X_FORWARDED_FOR'])
    // || !in_array(@$_SERVER['REMOTE_ADDR'], array(
    !in_array(@$_SERVER['REMOTE_ADDR'], array(
        '127.0.0.1',
        '216.220.243.154',
        '12.74.53.89',
        '172.16.243.1',
        '68.107.247.210',
        '::1',

        '144.217.146.145',

        // alex@home
        '72.208.149.101',
        // nate@tucson
        '24.23.66.136',
    ))
) {
    header('HTTP/1.0 403 Forbidden');
    print "-- " . $_SERVER['REMOTE_ADDR'] . " --<br />";
    exit('You are not allowed to access this file. Check '.basename(__FILE__).' for more information.');
}

/**
 * @var Composer\Autoload\ClassLoader
 */
$symlink_basepath = dirname($_SERVER['SCRIPT_FILENAME']);
$odr_instance_root = preg_replace('/\/web/', '', $symlink_basepath);

$loader = require $odr_instance_root . '/app/autoload.php';
require_once $odr_instance_root . '/app/bootstrap.php.cache';

$kernel = new AppKernel('dev', true);
$kernel->loadClassCache();
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
