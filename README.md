### Test

*Launch docker*

```
DB_HOST=db DB_USER=root DB_PASS=root DB_DBNAME=dbname MAPQUEST_API=xxxx docker-compose up

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