#!/bin/bash
set -e

test_defaul_get_issues(){
  curl -s http://localhost/get_issues.php | jq length
}

if [ "$(test_defaul_get_issues)" = "10" ]; then
    echo -e 'Success: get_issues.php should return 10 items'
else
    echo -e 'ERROR: get_issues.php :'
    curl -s http://localhost/get_issues.php
    exit 1
fi

test_count(){
  curl -s http://localhost/get_issues.php?count=2 | jq length
}

if [ "$(test_count)" = "2" ]; then
    echo -e 'Success: get_issues.php?count=2 should return 2 items'
else
    echo -e 'ERROR: get_issues.php?count=2 : ' $(test_count)
    curl -s http://localhost/get_issues.php?count=2
    exit 1
fi
