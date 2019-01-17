<?php
require_once('./common.php');
function RandomStringGenerator($n) 
{ 
    // Variable which store final string 
    $generated_string = ""; 
      
    // Create a string with the help of  
    // small letters, capital letters and 
    // digits. 
    $domain = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890"; 
      
    // Find the lenght of created string 
    $len = strlen($domain); 
      
    // Loop to create random string 
    for ($i = 0; $i < $n; $i++) 
    { 
        // Generate a random index to pick 
        // characters 
        $index = rand(0, $len - 1); 
          
        // Concatenating the character  
        // in resultant string 
        $generated_string = $generated_string . $domain[$index]; 
    } 
      
    // Return the random generated string 
    return $generated_string; 
} 


$token=RandomStringGenerator(30); 

$coordinates_lat = mysqli_real_escape_string($db,$_POST['coordinates_lat']);
$coordinates_lon = mysqli_real_escape_string($db,$_POST['coordinates_lon']);
$comment = mysqli_real_escape_string($db,$_POST['comment']);
$categorie = mysqli_real_escape_string($db,$_POST['categorie']);
$json = array('token' => $token, 'status' => 0);


mysqli_query($db,'INSERT INTO obs_list (`obs_coordinates_lat`,`obs_coordinates_lon`,`obs_comment`,`obs_categorie`,`obs_token`,`obs_time`,`obs_status`) VALUES
                                  ("'.$coordinates_lat.'","'.$coordinates_lon.'","'.$comment.'","'.$categorie.'","'.$token.'","'.time().'",0)') ;
if($mysqlerror = mysqli_error($db)) {
	$json['status'] = 1;
	error_log($mysqlerror);
}
echo json_encode($json);
?>
