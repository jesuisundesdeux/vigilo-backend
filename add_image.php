<?php
$token = $_GET['token'];

$data = file_get_contents('php://input');
$status = 0;

$filename = preg_replace('/[^A-Za-z0-9]/', '', $token);
# FIXME : securiser type fichier
if (!(file_put_contents('images/'.$filename.".jpg",$data) === FALSE)) ; 
else $status = 1;

echo json_encode(array('status'=>$status));

?>
