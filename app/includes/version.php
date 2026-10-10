<?php
/* Single source of the backend version: read by common.php, the Docker entrypoint
   and the release workflow (the git tag must be "v" + this value). */
define('BACKEND_VERSION', '0.0.29');
