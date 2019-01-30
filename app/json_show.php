<html>
<head>
    <title>Table Data Addition</title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.6/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-table/1.10.1/bootstrap-table.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/2.2.4/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.6/js/bootstrap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-table/1.10.1/bootstrap-table.min.js"></script>
<script>

var req = new XMLHttpRequest();
req.open('GET','https://vigilo.jesuisundesdeux.org/to_json.php');
req.responseType = 'json';
req.onload = function() {
  $(function () {
    $('#table').bootstrapTable({
        data: req.response
    });
  });
}
req.send();

var $table = $('#table');

</script>
</head>
<body>
<!--    <div class="container">-->
        <table id="table">
        <thead>
            <tr>
                <th data-field="token">Token</th>
                <th data-field="comment">Commentaire</th>
                <th data-field="address">Adresse</th>
                <th data-field="LON">LongitudeAdresse</th>
                <th data-field="LAT">Latitude</th>
                <th data-field="time">Date</th>
            </tr>
        </thead>
    </table>
<!--    </div>-->
</body>
</html>
