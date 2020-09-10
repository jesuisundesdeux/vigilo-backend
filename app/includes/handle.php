<?php
/*
Copyright (C) 2019 Velocité Montpellier

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
 any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/* Observations functions */
function getObsIdByToken($db,$token) {
  $checktoken_query = mysqli_query($db,"SELECT obs_id FROM obs_list WHERE obs_token='".$token."' LIMIT 1");
  if(mysqli_num_rows($checktoken_query) == 1) {
    $checktoken_result = mysqli_fetch_array($checktoken_query);
    return $checktoken_result['obs_id'];
  }
  else {
    return False;
  }
}

function isTokenExists($db,$token) {
  $checktoken_query = mysqli_query($db,"SELECT obs_token FROM obs_list WHERE obs_token='".$token."' LIMIT 1");
  if(mysqli_num_rows($checktoken_query) == 1) {
    return True;
  }
  else {
    return False;
  }
}

function isScopeExists($db,$scope) {
  $query_scope = mysqli_query($db, "SELECT * FROM obs_scopes WHERE scope_name='".$scope."' LIMIT 1");
  $result_scope = mysqli_fetch_array($query_scope);
  if (!$result_scope) {
    return False;
  }
  return True;
}

function isTokenWithSecretId($db,$token,$secretid) {
  $checktoken_query = mysqli_query($db,"SELECT obs_token FROM obs_list WHERE obs_token='".$token."' AND obs_secretid='".$secretid."' LIMIT 1");
  if(mysqli_num_rows($checktoken_query) == 1) {
    return True;
  }
  else {
    return False;
  }
}

function deleteObs($db,$obsid) {
  global $config['DATA_PATH'];

  mysqli_query($db,"DELETE FROM obs_list WHERE obs_id='".$obsid."' LIMIT 1");
  unlink($config['DATA_PATH'] . 'images/'.$token.'.jpg');
  delete_token_cache($token);
  delete_map_cache($token);

  $obsinresolution_query = mysqli_query($db,"SELECT restok_resolutionid FROM obs_resolutions_tokens WHERE restok_observationid='".$obsid."'");
  while($obsinresolution_result = mysqli_fetch_array($obsinresolution_query)) {
	  delObsToResolution($db,$obsid,$obsinresolution_result['restok_resolutionid']);
  }
}

/* Resolutions Functions */
function getResolutionIdByResolutionToken($db,$resolution_token) {
  $checkresolution_token_query = mysqli_query($db,"SELECT resolution_id FROM obs_resolutions WHERE resolution_token='".$resolution_token."' LIMIT 1");
  if(mysqli_num_rows($checkresolution_token_query) == 1) {
    $checkresolution_token_result = mysqli_fetch_array($checkresolution_token_query);
    return $checkresolution_token_result['resolution_id'];
  }
  else {
    return False;
  }
}

function updateResolution($db,$fields,$resolutionid) {
  $query_update = '';
  foreach($fields as $key => $value) {
    if($key == "resolution_comment" || $key == "resolution_time" || $key == "resolution_status") {
      $query_update .= "$key = '$value',";
    }
  }
  if(!empty($query_update)) {
    $query_update = substr($query_update, 0, -1);
    mysqli_query("UPDATE obs_resolutions SET ".$query_update." WHERE resolution_id = '".$resolutionid."'");
  }
}

function addObsToResolution($db,$obsid,$resolutionid) {
  $query_obs = mysqli_query($db,"SELECT * FROM obs_resolutions_tokens WHERE restok_resolutionid='".$resolutionid."' AND restok_observationid='".$obsid."' LIMIT 1");
  if (mysqli_num_rows($query_obs) != 1) {  
    mysqli_query($db,"INSERT INTO obs_resolutions_tokens (`restok_resolutionid`,`restok_observationid`) VALUES ('".$resolutionid."','".$obsid."')");
  }
}

function delObsToResolution($db,$obsid,$resolutionid) {
  mysqli_query($db,"DELETE FROM obs_resolutions_tokens WHERE restok_resolutionid='".$resolutionid."' AND restok_observationid='".$obsid."'");
  // Remove orphan resolutions
  $query_resolution = mysqli_query($db,"SELECT * FROM obs_resolutions_tokens WHERE restok_resolutionid='".$resolutionid."'");
  if (mysqli_num_rows($query_resolution) == 0) {
    mysqli_query($db,"DELETE FROM obs_resolutions WHERE resolution_id='".$resolutionid."'");
  }
}

function delResolution($db,$resolutionid) {
  $query_resolution = mysqli_query($db,"SELECT * FROM obs_resolutions_tokens WHERE restok_resolutionid='".$resolutionid."'");
  while($result_resolution = mysqli_fetch_array($query_resolution)) {
    delObsToResolution($db,$result_resolution['restok_observationid'],$resolutionid);
  }
}

function addResolution($db,$fields,$obsidlist) {
  mysqli_query($db, 'INSERT INTO obs_resolutions (
                                  `resolution_token`,
                                  `resolution_secretid`,
                                  `resolution_app_version`,
                                  `resolution_comment`,
                                  `resolution_time`,
                                  `resolution_status`)
                           VALUES ("'.$fields['resolution_token'].'","'.$fields['resolution_secretid'].'","'.$fields['resolution_app_version'].'","'.$fields['resolution_comment'].'","'.$fields['resolution_time'].'","'.$fields['resolution_status'].'")');
  error_log(mysqli_error($db));
  $resolution_id = mysqli_insert_id($db);
  
  foreach($obsidlist as $obs) {
    addObsToResolution($db,$obs,$resolution_id);
  }
}

function isResolutionTokenWithSecretId($db,$resolution_token,$secretid) {
  $checkresolutiontoken_query = mysqli_query($db,"SELECT resolution_id FROM obs_resolutions WHERE resolution_token='".$resolution_token."' AND resolution_secretid='".$secretid."' LIMIT 1");
  if(mysqli_num_rows($checkresolutiontoken_query) == 1) {
    return True;
  }
  else {
    return False;
  }
}

function isResolutionTokenExists($db,$resolution_token) {
  $checkresolutiontoken_query = mysqli_query($db,"SELECT resolution_id FROM obs_resolutions WHERE resolution_token='".$resolution_token."' LIMIT 1");
  if(mysqli_num_rows($checkresolutiontoken_query) == 1) {
    return True;
  }
  else {
    return False;
  }
}


function getObsStatus($db,$obsid) {
  $resolution_status_query = mysqli_query($db,"SELECT resolution_status 
                                                             FROM obs_resolutions 
                                                             LEFT JOIN obs_resolutions_tokens 
                                                             ON obs_resolutions.resolution_id = obs_resolutions_tokens.restok_resolutionid 
                                                             WHERE restok_observationid = '".$obsid."' LIMIT 1");
  $resolution_status_result = mysqli_fetch_array($resolution_status_query);
  $obs_status = ($resolution_status_result['resolution_status'] != null) ? $resolution_status_result['resolution_status'] : 0;
  return $obs_status;

}

function flushImagesCacheResolution($db,$resolutionid) {
  $obslist_query = mysqli_query($db, "SELECT obs_token FROM obs_resolutions_tokens LEFT JOIN obs_list ON obs_resolutions_tokens.restok_observationid = obs_list.obs_id WHERE restok_resolutionid='".$resolutionid."'");
  while($obslist_result = mysqli_fetch_array($obslist_query)) {
    delete_token_cache($obslist_result['obs_token']); 
  }
}

