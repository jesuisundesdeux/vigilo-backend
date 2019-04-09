#!/bin/bash

SCOPE=$1

. $(dirname $0)/config.sh

oneTimeSetUp () {
    echo "Waiting 10 sec for stating container ..."
    sleep 10
}

test_createGoodIssue () {
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lat=${LAT}\
&coordinates_lon=${LON}\
&categorie=1\
&address=ceci est une rue\
&comment=test test_createGoodIssue\
&scope=34_Montpellier\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | grep '"status":0' > /dev/null
    assertEquals $? 0
}

test_createIssueMissingLat () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lon=${LON}\
&categorie=1\
&address=ceci est une rue\
&comment=test test_createIssueMissingLat\
&scope=34_Montpellier\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | grep '"status":1' > /dev/null
    assertEquals $? 0
}

test_createIssueMissingLon () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lat=${LAT}\
&categorie=1\
&address=ceci est une rue\
&comment=test test_createIssueMissingLon\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | grep '"status":1' > /dev/null
    assertEquals $? 0
}

test_createIssueMissingCategorie () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "&coordinates_lat=${LAT}\
&coordinates_lon=${LON}\
&address=ceci est une rue\
&comment=test test_createIssueMissingCategorie\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | grep '"status":1' > /dev/null
    assertEquals $? 0
}

test_createIssueMissingAddress () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lat=${LAT}\
&coordinates_lon=${LON}\
&categorie=1\
&comment=test test_createIssueMissingAddress\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | grep '"status":1' > /dev/null
    assertEquals $? 0
}

test_createIssueMissingTime () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lat=${LAT}\
&coordinates_lon=${LON}\
&categorie=1\
&address=ceci est une rue\
&comment=test test_createIssueMissingTime\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | grep '"status":1' > /dev/null
    assertEquals $? 0
}

test_createIssueEmptyToken () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lat=${LAT}\
&coordinates_lon=${LON}\
&categorie=1\
&address=ceci est une rue\
&comment=test test_createIssueEmptyToken\
&scope=34_Montpellier\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | egrep -o '"token":"[0-9A-F]{8}"' > /dev/null
    assertEquals $? 0
}

test_createIssueSetedToken () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lat=${LAT}\
&coordinates_lon=${LON}\
&token=ABCDEF01\
&categorie=1\
&address=ceci est une rue\
&comment=test test_createIssueEmptyToken\
&scope=34_Montpellier\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | egrep -o '"token":"ABCDEF01"' > /dev/null
    assertEquals $? 0
}


test_createIssueSecretID () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lat=${LAT}\
&coordinates_lon=${LON}\
&categorie=1\
&address=ceci est une rue\
&comment=test test_createIssueSecretID
&scope=34_Montpellier\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | egrep -o '"secretid":"[0-9a-f]{22}"' > /dev/null
    assertEquals $? 0
}

test_getIssues () {
    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
"${VIGILO_SERVER}:${VIGILO_PORT}/get_issues.php"
    
    cat /tmp/curl.out | egrep -o '123456BA' > /dev/null
    assertEquals $? 0
}

. shunit2/shunit2