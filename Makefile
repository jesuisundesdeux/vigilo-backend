UID=$(shell id -u)
FROM=0.0.2
TO=0.0.2
SHUNIT=2.1.7
SCOPE=montpellier

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

init-db: ## Test application in docker container
	docker run --rm -ti -v ${pwd}:/data/ python sh -c "pip install docopt ; python /data/scripts/migrateDatabase.py -f ${FROM} -t ${TO} --test"
	docker-compose up -d

show-db:
	docker-compose exec db sh -c 'mysql -u root --password=$$MYSQL_ROOT_PASSWORD -e "select obs_id,obs_scope,obs_categorie,obs_address_string,obs_app_version,obs_approved,obs_token from obs_list;" vigilodb'


debug-db: init-db
	docker-compose logs --no-color -f db


test-app: shunit2 init-db
	cp scripts/${SCOPE}.sh scripts/config.sh
	scripts/testApp.sh

stop:
	docker-compose stop


clean: ## Clean some files
	-docker-compose rm -f
	-sudo rm -rf /data/docker/jsudd/


clean-packages: ## Clean some files
	-$(RM) -r shunit2*
