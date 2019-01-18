### Test

*Launch docker*

```
docker-compose up
```


*Test new creation*
```
curl -d 'coordinates_lat=43.6&coordinates_lon=3.9&comment=comment&categorie=1' -X POST 'http://localhost/create_issue.php'
```

*phpmyadmin*
Go to `http://localhost:8888`