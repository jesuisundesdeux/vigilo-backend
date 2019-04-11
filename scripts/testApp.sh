#!/bin/bash

SCOPE=$1

. $(dirname $0)/config.sh

oneTimeSetUp () {
    echo "Waiting 10 sec for stating container ..."
    sleep 10
}

# Test create issue with empty Lat field
test_createIssueMissingLat () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lon=${LON}\
&categorie=1\
&address=test_createIssueMissingLat\
&comment=test_createIssueMissingLat\
&scope=34_Montpellier\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | grep '"status":1' > /dev/null
    assertEquals $? 0
}

# Test create issue with empty Lon field
test_createIssueMissingLon () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lat=${LAT}\
&categorie=1\
&address=test_createIssueMissingLon\
&comment=test_createIssueMissingLon\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | grep '"status":1' > /dev/null
    assertEquals $? 0
}

# Test create issue with empty Categorie field
test_createIssueMissingCategorie () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "&coordinates_lat=${LAT}\
&coordinates_lon=${LON}\
&address=test_createIssueMissingCategorie\
&comment=test_createIssueMissingCategorie\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | grep '"status":1' > /dev/null
    assertEquals $? 0
}

# Test create issue with empty Address field
test_createIssueMissingAddress () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lat=${LAT}\
&coordinates_lon=${LON}\
&categorie=1\
&comment=test_createIssueMissingAddress\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | grep '"status":1' > /dev/null
    assertEquals $? 0
}

# Test create issue with empty Time field
test_createIssueMissingTime () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lat=${LAT}\
&coordinates_lon=${LON}\
&categorie=1\
&address=test_createIssueMissingTime\
&comment=test_createIssueMissingTime\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | grep '"status":1' > /dev/null
    assertEquals $? 0
}

# Test create issue with empty Token field
test_createIssueEmptyToken () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lat=${LAT}\
&coordinates_lon=${LON}\
&categorie=1\
&address=test_createIssueEmptyToken\
&comment=test_createIssueEmptyToken\
&scope=34_Montpellier\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | egrep -o '"token":"[0-9A-F]{8}"' > /dev/null
    assertEquals $? 0
}

# Test create issue and verify returned token
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
&address=test_createIssueSetedToken\
&comment=test_createIssueSetedToken\
&scope=34_Montpellier\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | egrep -o '"token":"ABCDEF01"' > /dev/null
    assertEquals $? 0
}

# Test create issue and verify returned SecretID
test_createIssueSecretID () {
    LAT=43.6029503
    LON=3.8822349
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lat=${LAT}\
&coordinates_lon=${LON}\
&categorie=1\
&address=test_createIssueSecretID
&comment=test_createIssueSecretID
&scope=34_Montpellier\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | egrep -o '"secretid":"[0-9a-f]{22}"' > /dev/null
    assertEquals $? 0
}

# Test create issue with good all parameters
test_createGoodIssue () {
    TIMESTAMP=$(date +"%s")

    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
    -d  "coordinates_lat=${LAT}\
&coordinates_lon=${LON}\
&categorie=1\
&address=test_createGoodIssue\
&comment=test_createGoodIssue\
&scope=34_Montpellier\
&time=${TIMESTAMP}\
" -X POST "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"
    
    cat /tmp/curl.out | grep '"status":0' > /dev/null
    assertEquals $? 0
}


# Test retrieving issue
test_getIssues () {
    rm -f /tmp/curl.out
    curl -s --output /tmp/curl.out \
"${VIGILO_SERVER}:${VIGILO_PORT}/get_issues.php"
    
    cat /tmp/curl.out | egrep -o '"token": "5UNYMG1Y",' > /dev/null
    assertEquals $? 0
}


# test token is uniq
test_uniqToken () {
    docker-compose exec db sh -c 'mysql --skip-column-names --batch --raw -u root --password=$MYSQL_ROOT_PASSWORD -e "select max(counted) from (select obs_token,count(*) as counted from obs_list group by obs_token) as counts;" vigilodb' | egrep '^1' > /dev/null
    assertEquals $? 0
}

. shunit2/shunit2