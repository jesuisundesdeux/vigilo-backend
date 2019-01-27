### Test

*Launch docker*

```
MAPQUEST_API=xxxx docker-compose up
MYSQL_INIT_FILE=other_init_sql_file in mysql folder
# sudo chown -R $(id -u):$(id -g) ./app/
```


*Test new creation*
```
./debug/create_issue.sh
```

*phpmyadmin*
Go to `http://localhost:8888`