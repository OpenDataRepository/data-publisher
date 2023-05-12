#!/bin/bash

#./run_phpunit_tests.sh --testsuite=services
#./run_phpunit_tests.sh --testsuite=api --debug

# pass all arguments given to this script to phpunit, ensuring the phpunit config file is the final argument
php bin/phpunit $* -c app/phpunit_tests.xml
