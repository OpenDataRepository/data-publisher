#!/bin/bash

DEBUG="testGeneralSearchA" 



for i in {1..30}; do php bin/phpunit -c app/phpunit.xml.dist; done
