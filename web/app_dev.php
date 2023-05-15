<?php

umask(0000);
use Symfony\Component\HttpFoundation\Request;

// If you don't want to setup permissions the proper way, just uncomment the following PHP line
// read http://symfony.com/doc/current/book/installation.html#configuration-and-setup for more information
//umask(0000);

// This check prevents access to debug front controllers that are deployed by accident to production servers.
// Feel free to remove this, extend it, or make something more sophisticated.
if (
    // isset($_SERVER['HTTP_CLIENT_IP'])
    // || isset($_SERVER['HTTP_X_FORWARDED_FOR'])
    // || !in_array(@$_SERVER['REMOTE_ADDR'], array(
    !in_array(@$_SERVER['REMOTE_ADDR'], array(
        '144.217.146.144',   // nu

        '68.106.231.40',
        '216.220.243.164',

        '68.231.130.57',     // alex@home
        '128.196.236.84',    // alex@UofA

        '127.0.0.1',
        '::1',
    ))
) {
    header('HTTP/1.0 403 Forbidden');
    print "-- " . $_SERVER['REMOTE_ADDR'] . " --<br />";
    exit('You are not allowed to access this file. Check '.basename(__FILE__).' for more information.');
}

/**
 * @var Composer\Autoload\ClassLoader
 */
$loader = require __DIR__.'/../app/autoload.php';
$loader = require_once __DIR__.'/../app/bootstrap.php.cache';
require_once __DIR__.'/../app/AppKernel.php';

$kernel = new AppKernel('dev', true);
$kernel->loadClassCache();
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
