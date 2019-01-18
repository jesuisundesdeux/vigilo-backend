### Test

*Launch docker*

```
MAPQUEST_API=xxxx docker-compose up
# sudo chown -R $(id -u):$(id -g) ./app
```


*Test new creation*
```
curl -d 'coordinates_lat=43.6076439&coordinates_lon=3.8789876&comment=comment&categorie=1' -X POST 'http://localhost/create_issue.php'
```

*phpmyadmin*
Go to `http://localhost:8888`