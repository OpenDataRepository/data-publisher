<?php

// doctrine/annotations 2.0 removed AnnotationRegistry::registerLoader(); annotation classes are
// now resolved through Composer's autoloader, so no manual registration is needed.

$loader = require __DIR__.'/../vendor/autoload.php';

// intl
if (!function_exists('intl_get_error_code')) {
    require_once __DIR__.'/../vendor/symfony/symfony/src/Symfony/Component/Locale/Resources/stubs/functions.php';

    $loader->add('', __DIR__.'/../vendor/symfony/symfony/src/Symfony/Component/Locale/Resources/stubs');
}

return $loader;
