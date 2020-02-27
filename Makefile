ENV:=unittest
UID=$(shell id -u)
WWW_DATA_UID=33
SHUNIT=2.1.7
SCOPE=montpellier
NOW:=$(shell date +'%Y%m%d%H%M%S')
WAIT:=8

# Database information
BKDATE=NODATE

# Get .env parameters
MYSQL_HOST :=$(shell cat .env_${ENV} | grep MYSQL_HOST | cut -d"=" -f2)
MYSQL_ROOT_PASSWORD :=$(shell cat .env_${ENV} | grep MYSQL_ROOT_PASSWORD | cut -d"=" -f2)
MYSQL_DATABASE :=$(shell cat .env_${ENV} | grep MYSQL_DATABASE | cut -d"=" -f2)
MYSQL_INIT_FILE :=$(shell cat .env_${ENV} | grep MYSQL_INIT_FILE | cut -d"=" -f2)
VOLUME_PATH :=$(shell cat .env_${ENV} | grep VOLUME_PATH | cut -d"=" -f2)
BIND :=$(shell cat .env_${ENV} | grep BIND | cut -d"=" -f2)
TO :=$(shell ls mysql/init/init-*.sql | sort -V | tail -1 | sed -e "s/.*init-//" -e s"/\.sql//")

ifneq ("$(wildcard version.txt)","")
	FROM:=$(shell cat version.txt)
	INSTALLED=TRUE
else
	FROM=0.0.2
	INSTALLED=FALSE
endif

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

create-db: ## Init empty database

	@echo > mysql/sql_migration.sql
	docker run --rm -ti -v ${pwd}:/data/ python sh -c "pip install docopt natsort; python /data/scripts/migrateDatabase.py -f ${FROM} -t ${TO}"

init-db: ## Init database with unit tests datas
	docker run --rm -ti -v ${pwd}:/data/ python sh -c "pip install docopt natsort; python /data/scripts/migrateDatabase.py -f ${FROM} -t ${TO} --test"

backup-db: ## Backup a mysql docker container
	docker-compose -f docker-compose.yml exec db sh -c 'mysqldump -u root --password=$$MYSQL_ROOT_PASSWORD --single-transaction --skip-lock-tables --databases ${MYSQL_DATABASE} | gzip -c -9 > /dump/${MYSQL_DATABASE}-${NOW}.sql.gz'


restore-db: ## Restore a mysql docker container
	#docker run --rm -ti -v $(pwd)/mysql/dump:/dump mysql sh -c 'mysql -h ${MYSQL_HOST} -u root --password=${MYSQL_ROOT_PASSWORD} ${MYSQL_DATABASE} < /dump/${DBFILE}'
	cp $(pwd)/backup/mysql/dump-${BKDATE}.sql mysql/${MYSQL_INIT_FILE}

env: ## copy docker-compose -f docker-compose_${ENV}.yml .env environment
	cp .env_${ENV} .env

show-db:  ## Show database content
	docker-compose -f docker-compose.yml exec db sh -c 'mysql -u root --password=$$MYSQL_ROOT_PASSWORD -e \
	"SELECT obs_id,obs_scope,obs_categorie,obs_address_string,obs_app_version,obs_approved,obs_token \
	FROM obs_list;" ${MYSQL_DATABASE}'

show-db-scope:
	docker-compose -f docker-compose.yml exec db sh -c 'mysql -u root --password=$$MYSQL_ROOT_PASSWORD -e \
	"SELECT scope_coordinate_lat_min, scope_coordinate_lat_max, scope_coordinate_lon_min, scope_coordinate_lon_max \
	FROM obs_scopes;" ${MYSQL_DATABASE}'

list-bundle: ## list a bundle backups
	@-ls -alh backup/bundle | grep "bundle-" | sed 's/bundle-//' | sed 's/\.tgz//'


backup-bundle: backup-db ## backup database and image files
	tar -cvzf backup/bundle/bundle-${NOW}.tgz backup/mysql/dump-${NOW}.sql app/caches app/images app/maps


restore-bundle: ## Restore a bundle backup
	test -e app/caches && rm -f app/caches/*
	test -e app/images && rm -f app/images/*
	test -e app/maps && rm -f app/maps/*
	tar -xvzf backup/bundle/bundle-${BKDATE}.tgz
	sudo rsync -avr app/caches app/images app/maps ${VOLUME_PATH}/files/
	sudo chown -R ${WWW_DATA_UID} ${VOLUME_PATH}/files/caches ${VOLUME_PATH}/files/images ${VOLUME_PATH}/files/maps
	cp backup/mysql/dump-${BKDATE}.sql mysql/${MYSQL_INIT_FILE}


debug-db: env init-db
	docker-compose -f docker-compose.yml logs --no-color -f db


unittest: env
	docker-compose -f docker-compose.yml up -d
	docker-compose -f docker-compose.yml exec web phpunit -c phpunit.xml


start: env ## Start a docker compose stack
	@docker-compose -f docker-compose.yml up -d
	@echo "Waiting ${WAIT} sec for stating container and restoring database ..."
	@sleep ${WAIT}


test-webserver: shunit2
	cp scripts/${SCOPE}.sh scripts/config.sh
	scripts/testApp.sh ${SCOPE}


stop: env ## Stop a docker stack
	docker-compose -f docker-compose.yml stop


clean: .env ## Clean some files
	-docker-compose -f docker-compose.yml rm -f
	-test -e /data/docker/jsudd-${ENV} && sudo rm -rf /data/docker/jsudd-${ENV}
	-test -e mysql/${MYSQL_INIT_FILE} && sudo rm -rf mysql/${MYSQL_INIT_FILE}


clean-packages: ## Clean some files
	-$(RM) -r shunit2*


install: env create-db start
	cp install_app/install.php app/install.php
	@echo "Listening on ${BIND}"
	@echo "Please go on http://${BIND}/install.php"
	@echo "${TO}" > version.txt

upgrade-db: backup-db create-db # Upgrade DB structure
	docker-compose -f docker-compose.yml exec db sh -c 'mysql -u root --password=$$MYSQL_ROOT_PASSWORD ${MYSQL_DATABASE} < /docker-entrypoint-initdb.d/sql_migration.sql'
	@echo "Upgrade from ${FROM} to ${TO}"
	@echo "${TO}" > version.txt
	@echo > mysql/sql_migration.sql

install-with-data: env init-db start
		cp install_app/install.php app/install.php
		@echo "Listening on ${BIND}"
		@echo "Please go on http://${BIND}/install.php"
		@echo "${TO}" > version.txt
