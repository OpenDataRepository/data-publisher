<?php

umask(0000);
use Symfony\Component\HttpFoundation\Request;

// If you don't want to setup permissions the proper way, just uncomment the following PHP line
// read http://symfony.com/doc/current/book/installation.html#configuration-and-setup for more information
//umask(0000);

// This check prevents access to debug front controllers that are deployed by accident to production servers.
// Feel free to remove this, extend it, or make something more sophisticated.
/*
if (
    // isset($_SERVER['HTTP_CLIENT_IP'])
    // || isset($_SERVER['HTTP_X_FORWARDED_FOR'])
    // || !in_array(@$_SERVER['REMOTE_ADDR'], array(
    !in_array(@$_SERVER['REMOTE_ADDR'], array(
        '127.0.0.1',
        '198.228.201.157',
        '72.224.225.243',
        '198.228.201.167',
        '12.130.117.57',
        '68.84.197.187',
        '206.207.225.64',
        '72.224.234.183',
        '12.130.117.41',
        '166.147.72.45',
        '166.137.156.16',
        '198.228.200.155',
        '72.224.172.177',
        '198.228.201.153',
        '173.12.248.33',
	    '72.224.225.50',
	    '68.228.242.208',
        '128.196.236.84',
    	'64.134.65.145',
    	'198.228.200.174',
        '166.137.88.42',
        '75.128.96.205',
        '166.137.119.47',
        '166.137.119.21',
        '173.166.205.41',
        '12.53.193.10',
        '72.224.172.21',
        '198.228.201.161',
        '184.107.129.138',
        '::1',
    ))
) {
    header('HTTP/1.0 403 Forbidden');
    print "-- " . $_SERVER['REMOTE_ADDR'] . " --<br />";
    exit('You are not allowed to access this file. Check '.basename(__FILE__).' for more information.');
}
*/

$loader = require_once __DIR__.'/../app/bootstrap.php.cache';
require_once __DIR__.'/../app/AppKernel.php';

$kernel = new AppKernel('dev', true);
$kernel->loadClassCache();
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
