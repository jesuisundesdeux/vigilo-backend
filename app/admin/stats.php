<?php
require_once('../common.php');
require_once('../functions.php');

// Script to map or create obs gorups

$query = mysqli_query($db,"SELECT * FROM obs_list");
$groups = array();
while($result = mysqli_fetch_array($query)) {
  $in_group = 0;
  $token = $result['obs_token'];
  $categorie = $result['obs_categorie'];
  $coordinates_lat = $result['obs_coordinates_lat'];
  $coordinates_lon = $result['obs_coordinates_lon'];
  $address = $result['obs_address_string'];

  foreach($groups as $key => $value) {
#    if($value['categorie'] == $categorie) {
      if(str_replace(' ','',$value['address_string']) == str_replace(' ','',$address)) {
	      $groups[$key]['tokens'][] = $token;
	      $groups[$key]['count']++;
	      $in_group = 1;
	      break;
      }
      elseif(distance($value['coordinates_lat'], $value['coordinates_lon'], $coordinates_lat, $coordinates_lon, $unit = 'm') < 200) {
	      $groups[$key]['tokens'][] = $token;
	      $groups[$key]['count']++;
	      $in_group = 1;
	      break;
      }
   # }
  }
  if($in_group == 0) {
	  $groups[] = array("categorie" => $categorie, 'address_string' => $address, 'coordinates_lat' => $coordinates_lat, 'coordinates_lon' => $coordinates_lon, 'tokens' => array($token), 'count' => 1);
  }
}

$count = array_column($groups, "count");

array_multisort($count, SORT_DESC, $groups);
?>
<pre>
<?php
var_dump($groups);
?></pre>

