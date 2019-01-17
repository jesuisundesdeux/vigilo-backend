### Test

*Launch docker*

```
docker-compose up
```


*Test new creation*
```
curl -d 'coordinates_lat=0&coordinates_lon=0&comment=comment&categorie=categorie' -X POST 'http://localhost/create_issue.php'
```

*phpmyadmin*
Go to `http://localhost:8888`