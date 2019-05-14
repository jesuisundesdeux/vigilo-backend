### Upgrade

#### 0.0.7 to 0.0.8

* Launch mysql/init/init-0.0.8.sql
* Update sources code 

#### 0.0.8 to 0.0.9

##### All

* Launch mysql/init/init-0.0.9.sql in MySQL
* Upload install_app/upgrade.php in the app root
* Execute /upgrade.php?from=0.0.8&to=0.0.9
* Remove /upgrade.php
* Update sources code
* Clean config.php (remove everyvariables except /* Database configuration */)

##### Docker only

* Update docker_compose as docker_compose_sample
