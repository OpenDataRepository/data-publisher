<?php

umask(0000);
//use Symfony\Component\ClassLoader\ApcClassLoader;
//use Symfony\Component\HttpFoundation\Request;
//
///**
// * @var Composer\Autoload\ClassLoader
// */
//$loader = require __DIR__.'/../app/autoload.php';
//$loader = require_once __DIR__.'/../app/bootstrap.php.cache';
//
//// Use APC for autoloading to improve performance
//// Change 'sf2' by the prefix you want in order to prevent key conflict with another application
//$loader = new ApcClassLoader('odr_dev', $loader);
//$loader->register(true);
//
//require_once __DIR__.'/../app/AppKernel.php';
//require_once __DIR__.'/../app/AppCache.php';
//
//$kernel = new AppKernel('prod', false);
//$kernel->loadClassCache();
//$kernel = new AppCache($kernel);
//$request = Request::createFromGlobals();
//$response = $kernel->handle($request);
//$response->send();
//$kernel->terminate($request, $response);




use Symfony\Component\HttpFoundation\Request;
/**
 * @var Composer\Autoload\ClassLoader
 */
$loader = require __DIR__.'/../app/autoload.php';
// include_once __DIR__.'/../var/bootstrap.php.cache';
require_once __DIR__.'/../app/bootstrap.php.cache';
$kernel = new AppKernel('prod', false);
$kernel->loadClassCache();
// $kernel = new AppCache($kernel);
// When using the HttpCache, you need to call the method in your front controller instead of relying on the configuration parameter
//Request::enableHttpMethodParameterOverride();
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
