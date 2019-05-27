### Upgrade

#### 0.0.8 to 0.0.9

##### All

* Do NOT update your source code à this stage. Stay in 0.0.8.
* Download from Github and launch mysql/init/init-0.0.9.sql in MySQL

wget https://raw.githubusercontent.com/jesuisundesdeux/vigilo-backend/master/install_app/upgrade.php -O app/upgrade.php

* Upload install_app/upgrade.php in the app root
* Execute /upgrade.php and make sure there is no error
* Execute /upgrade.php?from=0.0.8&to=0.0.9
* Clean config/config.php as explained in upgrade.php. (Remove all variables except for the « Database configuration » block with the following variables: MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE, MYSQL_CHARSET)
* Remove /upgrade.php from your app directory
* Update sources code

##### Docker only

* Update your .env file and align it with .env_sample
* Update docker_compose as docker_compose_sample

#### 0.0.7 to 0.0.8

* Launch mysql/init/init-0.0.8.sql
* Update sources code

