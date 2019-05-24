# Vigilo Backend [![Build Status](https://travis-ci.org/jesuisundesdeux/vigilo-backend.svg?branch=master)](https://travis-ci.org/jesuisundesdeux/vigilo-backend)
REST API to manage observations of the [Vigilo app](https://vigilo.city/fr/).

Documentation can be found [here](https://github.com/jesuisundesdeux/vigilo-backend/tree/master/doc).

## Development Quick Start
```
# Start server with 10 observations
make install-with-data

# verify you can get all observations http://localhost/get_issues.php
```

## Test
### Unit test
```
# PHP unittest with mysql server
make ENV=unittest stop clean init-db start unittest
```

### Functional test - shunit2
```
# make install-with-data (required)
make test-webserver
```
Test library : https://github.com/kward/shunit2/

### Tools

```
# List all available commands
make

# Start server with data
make install-with-data

# PHP unittest without mysql server
make unittest

# PHP unittest with mysql server
make ENV=unittest stop clean init-db start unittest

# Test application with cleaned database
make ENV=dev SCOPE=montpellier stop clean init-db start test-webserver show-db

# Start server with backuped database
#make backup-db DBSERVER=192.168.0.1
make SCOPE=montpellier BKDATE=20190412233147 stop clean restore-db start show-db

# Startserver with backuped bundle
#make backup-bundle DBSERVER=192.168.0.1
make SCOPE=montpellier BKDATE=20190412233147 stop clean restore-bundle start show-db
```
