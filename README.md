Open Data Repository Data Publisher
Version 2.82
===================================

The Open Data Repository's Data Publisher aims to create a simple tool
for publishing data to the web.  The project will allow non-technical
users to design web layouts and their underlying database structures
through a web-based, intuitive interface.

The current code is BETA code and should not be used in a production 
environment.  If you are interested in testing the code or contributing
to the project, this edition is viable for these purposes only.

1) Installation
----------------------------------

This project runs on Symfony 7.4 (LTS) and PHP 8.2+ (developed/tested on PHP
8.3); it installs by cloning this repository and then using Composer to install
the required Symfony dependencies.  (See UPGRADE_PLAN.md for the history of the
3.4 → 7.4 upgrade.)

Additionally, you must have the following support services to
run the publisher engine:

beanstalkd - https://github.com/kr/beanstalkd  (job queue)
memcached  - http://memcached.org             (Doctrine + session cache)
redis      - https://redis.io                 (caching / locks)
Node.js    - https://nodejs.org               (background_services/ workers)

> git clone https://github.com/OpenDataRepository/data-publisher.git

After cloning the repository, modify the following files with the
appropriate values for your system.  

app/config/parameters.yml.dist
app/config/security.yml

Look for lines with double brackets.  These lines need values specific
to your configuraiton (ie:  [[ my_database_name ]]

Use Composer (*recommended*) to download and  update the Symfony2
distribution and required dependencies.

> composer update

Next run "regenerate_and_update.sh" to ensure your database is properly
created and up-to-date.

> bash regenerate_and_update.sh

### JWT keys (required for the API)

The API token endpoints (/api/v3/token, /api/v4/token, /api/v5/token) sign their
JSON Web Tokens with an RSA key pair.  These keys are environment-specific
secrets and are intentionally NOT committed to version control
(app/config/jwt/ is gitignored), so **every environment — dev, staging, and
production — must generate its own key pair**:

> php app/console lexik:jwt:generate-keypair

This writes:

    app/config/jwt/private.pem   (signs tokens)
    app/config/jwt/public.pem    (verifies tokens)

The key paths and the (passphrase) are configured under
"lexik_jwt_authentication" in app/config/config.yml; the keys must be generated
with the same pass_phrase that is set there.  Without these keys, requesting an
API token fails with "Unable to create a signed JWT from the given
configuration".  Regenerating the keys invalidates any tokens already issued.



2) Testing
----------------------------------

### PHPUnit (Backend)

Run all PHPUnit tests:

> php bin/phpunit -c app/phpunit.xml.dist

Run with debug output:

> DEBUG=APIController php bin/phpunit -c app/phpunit.xml.dist

Run a specific test file:

> php bin/phpunit -c app/phpunit.xml.dist src/ODR/AdminBundle/Tests/Controller/APIControllerTest.php

### Playwright (Screenshot Regression)

Screenshot tests use Playwright to capture and compare screenshots of key pages.
Requires Node.js and a running instance of the application at http://odr.io.

Install dependencies:

> npm install
> npx playwright install chromium

Capture a new baseline (creates a timestamped snapshot directory):

> npm run test:capture

Compare against the latest baseline:

> npm test

Compare against a specific baseline:

> BASELINE=202604062126 npm test

View the test report:

> npm run test:report

Snapshots are stored in `tests/screenshots/snapshots/{YYYYMMDDHHMM}/` with
subdirectories for each spec file (admin, public, search).