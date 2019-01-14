<?php
$token = $_GET['token'];

$data = file_get_contents('php://input');
$status = 0;

# FIXME : securiser $token et type de fichier

if (!(file_put_contents('images/'.$token.".jpg",$data) === FALSE)) ; 
else $status = 1;

echo json_encode(array('status'=>$status));

?>
