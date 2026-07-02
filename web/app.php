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

// Resolve the instance root from the *requested* script path rather than __DIR__.
// __DIR__ follows symlinks, so on a symlinked instance (e.g. dev.rruff.net) it
// would resolve back to the shared source tree — loading that instance's autoload,
// kernel, cache, logs, and config from the wrong place. SCRIPT_FILENAME keeps the
// unresolved instance path. ODR_APP_DIR feeds AppKernel::getProjectDir() so cache,
// logs, and config all resolve to the instance too. On a normal (non-symlinked)
// install this is identical to the __DIR__-based path.
$symlink_basepath = dirname($_SERVER['SCRIPT_FILENAME']);   // the web/ dir, as requested
$odr_instance_root = preg_replace('#/web$#', '', $symlink_basepath);   // strip the trailing web/
if (!defined('ODR_APP_DIR'))
    define('ODR_APP_DIR', $odr_instance_root.'/app');

/**
 * @var Composer\Autoload\ClassLoader
 */
$loader = require $odr_instance_root.'/app/autoload.php';
require_once $odr_instance_root.'/app/AppKernel.php';
$kernel = new AppKernel('prod', false);

// When using the HttpCache, you need to call the method in your front controller instead of relying on the configuration parameter
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
