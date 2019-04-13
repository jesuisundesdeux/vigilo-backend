UID=$(shell id -u)
FROM=0.0.2
TO=0.0.2
SHUNIT=2.1.7
SCOPE=montpellier
NOW=$(shell date +'%Y%m%d%H%M%S')

# Database information
DBNAME=vigilodb
DBFILE=dump.sql
DBSERVER=127.0.0.1
DBPASSWORD=xxxx

makefile_path := $(abspath $(lastword $(MAKEFILE_LIST)))
pwd := $(dir $(makefile_path))

all: help

help:
	@grep "##" Makefile | grep -v "@grep"

shunit2:
	wget https://github.com/kward/shunit2/archive/v${SHUNIT}.tar.gz
	tar -xvzf v${SHUNIT}.tar.gz
	ln -s shunit2-${SHUNIT} shunit2
	rm v${SHUNIT}.tar.gz

init-db: ## Init database with unit tests datas
	docker run --rm -ti -v ${pwd}:/data/ python sh -c "pip install docopt ; python /data/scripts/migrateDatabase.py -f ${FROM} -t ${TO} --test"

backup-db: ## Backup a mysql docker container
	docker run --rm -ti -v $(pwd)/mysql/dump:/dump mysql sh -c 'MYSQL_PWD=${DBPASSWORD} mysqldump -h ${DBSERVER} -u root --single-transaction --skip-lock-tables --column-statistics=0 --databases ${DBNAME} > /dump/dump-${NOW}.sql'

restore-db: ## Restore a mysql docker container
	#docker run --rm -ti -v $(pwd)/mysql/dump:/dump mysql sh -c 'mysql -h ${DBSERVER} -u root --password=${DBPASSWORD} ${DBNAME} < /dump/${DBFILE}'
	cp mysql/dump/${DBFILE} mysql/sql_migration.sql 

show-db:
	docker-compose exec db sh -c 'mysql -u root --password=$$MYSQL_ROOT_PASSWORD -e "select obs_id,obs_scope,obs_categorie,obs_address_string,obs_app_version,obs_approved,obs_token from obs_list;" vigilodb'

debug-db: init-db
	docker-compose logs --no-color -f db

unittest: shunit2
	cp scripts/${SCOPE}.sh scripts/config.sh
	scripts/testApp.sh

start:
	@docker-compose up -d
	@echo "Waiting 10 sec for stating container and restoring database ..."
	@sleep 10

test-app: shunit2
	cp scripts/${SCOPE}.sh scripts/config.sh
	scripts/testApp.sh

stop:
	docker-compose stop


clean: ## Clean some files
	-docker-compose rm -f
	-test -e /data/docker/jsudd && sudo rm -rf /data/docker/jsudd/
	-test -e mysql/sql_migration.sql && sudo rm -rf mysql/sql_migration.sql

clean-packages: ## Clean some files
	-$(RM) -r shunit2*
