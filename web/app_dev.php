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
        '127.0.0.1',
        '216.220.243.154',
        '172.16.243.141',
        '172.16.243.1',
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

// Resolve the instance root from the *requested* script path rather than __DIR__,
// which follows symlinks (see web/app.php for the full rationale). ODR_APP_DIR feeds
// AppKernel::getProjectDir() so cache/logs/config resolve to the linked instance.
$symlink_basepath = dirname($_SERVER['SCRIPT_FILENAME']);   // the web/ dir, as requested
$odr_instance_root = preg_replace('#/web$#', '', $symlink_basepath);   // strip the trailing web/
if (!defined('ODR_APP_DIR'))
    define('ODR_APP_DIR', $odr_instance_root.'/app');

/**
 * @var Composer\Autoload\ClassLoader
 */
$loader = require $odr_instance_root.'/app/autoload.php';
require_once $odr_instance_root.'/app/AppKernel.php';

$kernel = new AppKernel('dev', true);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
