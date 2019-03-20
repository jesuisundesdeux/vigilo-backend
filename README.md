### Test

*Launch docker*

```
MYSQL_HOST=db MYSQL_USER=root MYSQL_PASSWORD=root MYSQL_DATABASE=vigilodb MAPQUEST_API=xxxx docker-compose up

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
