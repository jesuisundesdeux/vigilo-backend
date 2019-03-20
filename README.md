### Test

*Launch docker*

* Get an API Key pour the StaticMAP API on https://developer.mapquest.com/
* Create a Twitter accound / create application on it and Get the keys (https://creerapplication.zendesk.com/hc/fr/articles/115000691364-Int%C3%A9grer-Twitter-dans-votre-application)
* Then you are able to fill the "xxx" in ".env" and launch docker-compose

```
docker-compose up

# Optional
# MYSQL_INIT_FILE=other_init_sql_file in mysql folder

# sudo chown -R $(id -u):$(id -g) ./app/
```


*Test new creation*
```
./debug/create_issue.sh
```

*phpmyadmin*
Go to `http://localhost:8888`
