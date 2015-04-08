Open Data Repository Data Publisher
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

This project is based on Symfony 2.5 and installs by cloning this 
repository and then using cmposer to install the required Symfony 
dependencies.

Additionally, you must have the following support libraries to 
run the publisher engine:

beanstalkd - https://github.com/kr/beanstalkd
memcached - http://memcached.org

> git clone https://github.com/OpenDataRepository/data-publisher.git

After cloning the repository, modify the following files with the
appropriate values for your system.  

app/config/parameters.yml.dist
app/config/security.yml

Look for lines with double brackets.  These lines need values specific
to your configuraiton (ie:  [[ my_database_naem ]]

Use Composer (*recommended*) to download and  update the Symfony2
distribution and required dependencies.

> composer update

Next run "regenerate_and_update.sh" to ensure your database is properly
created and up-to-date.

> bash regenerate_and_update.sh






