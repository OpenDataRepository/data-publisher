# Test-infra blocker — SearchAPIServiceTest (and the whole PHPUnit suite) cannot boot

## Symptom
`php bin/phpunit -c app/phpunit.xml.dist <any test>` dies at load time:

    Uncaught Error: Call to undefined method
    Doctrine\Common\Annotations\AnnotationRegistry::registerLoader()
    in vendor/symfony/phpunit-bridge/bootstrap.php:124

No individual test "fails" — PHPUnit never reaches a test method; it crashes in the bridge bootstrap.

## Root cause
The SF7 upgrade moved the app to `doctrine/annotations ^2.0` (2.0.2 installed), which removed BOTH
`AnnotationRegistry::registerUniqueLoader()` and `::registerLoader()`. But the test stack was left at
pre-upgrade versions:
  - require-dev: `symfony/phpunit-bridge ^4.4`  (installed v4.4.49 — Symfony 4.4 era)
  - require-dev: `phpunit/phpunit ^7.5`
The v4.4 bridge bootstrap unconditionally calls the removed `registerLoader()`. This is the unfinished
"PHPUnit 7.5 -> 11.x" migration noted in CLAUDE.md / the upgrade plan.

## Why it can't be fixed on this dev box
  1. `composer` is not installed here, so the dependency bump can't be performed.
  2. The crash is in a vendor file; hand-patching it would be overwritten on the next composer install.

## The fix (run in a composer-capable environment)
  composer require --dev --update-with-all-dependencies \
      "symfony/phpunit-bridge:^7.4" "phpunit/phpunit:^11.5"
  # then update app/phpunit.xml.dist to the PHPUnit 11 schema if needed, and adjust any
  # test base-classes that relied on the bridge's annotation loader.

## Also note: SearchAPIServiceTest is environment-pinned
Even once it boots, every case is guarded by:
    if ( $client->getContainer()->getParameter('database_name') !== 'odr_theta_2' )
        $this->markTestSkipped('Wrong database');
so it only validates against the `odr_theta_2` fixture database (this box runs odr_prod_20251103).
Run the search-correctness validation of the develop-sync core rework in the odr_theta_2 test env.
