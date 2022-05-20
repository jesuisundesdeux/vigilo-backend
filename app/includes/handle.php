<?php
/*
Copyright (C) 2020 Velocité Montpellier

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.    See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA    02111-1307    USA
*/

/* Observations functions */
function getObsIdByToken($token)
{
    global $db;
    $checktoken_query = mysqli_query($db, "SELECT obs_id FROM obs_list WHERE obs_token='" . $token . "' LIMIT 1");
    if (mysqli_num_rows($checktoken_query) == 1) {
        $checktoken_result = mysqli_fetch_array($checktoken_query);
        return $checktoken_result['obs_id'];
    } else {
        return False;
    }
}

function getTokenByObsid($obsid)
{
    global $db;
    
    $checktoken_query = mysqli_query($db, "SELECT obs_token FROM obs_list WHERE obs_id='" . $obsid . "' LIMIT 1");
    if (mysqli_num_rows($checktoken_query) == 1) {
        $checktoken_result = mysqli_fetch_array($checktoken_query);
        return $checktoken_result['obs_token'];
    } else {
        return False;
    }
}

function isTokenExists($token)
{
    global $db;
    $checktoken_query = mysqli_query($db, "SELECT obs_token FROM obs_list WHERE obs_token='" . $token . "' LIMIT 1");
    if (mysqli_num_rows($checktoken_query) == 1) {
        return True;
    } else {
        return False;
    }
}

function isScopeExists($scope)
{
    global $db;
    $query_scope  = mysqli_query($db, "SELECT * FROM obs_scopes WHERE scope_name='" . $scope . "' LIMIT 1");
    $result_scope = mysqli_fetch_array($query_scope);
    if (!$result_scope) {
        return False;
    }
    return True;
}

function isTokenWithSecretId($token, $secretid)
{
    global $db;
    $checktoken_query = mysqli_query($db, "SELECT obs_token FROM obs_list WHERE obs_token='" . $token . "' AND obs_secretid='" . $secretid . "' LIMIT 1");
    if (mysqli_num_rows($checktoken_query) == 1) {
        return True;
    } else {
        return False;
    }
}

function deleteObs($obsid)
{
    global $db;
    global $config;
    $cwd         = dirname(__FILE__);
    $images_path = "${cwd}" . '/../' . $config['DATA_PATH'] . "images/";
    $token       = getTokenByObsid($obsid);
    
    mysqli_query($db, "DELETE FROM obs_list WHERE obs_id='" . $obsid . "' LIMIT 1");
    unlink($images_path . $token . '.jpg');
    
    if ($token) {
        delete_token_cache($token);
        delete_map_cache($token);
    }
    
    $obsinresolution_query = mysqli_query($db, "SELECT restok_resolutionid FROM obs_resolutions_tokens WHERE restok_observationid='" . $obsid . "'");
    while ($obsinresolution_result = mysqli_fetch_array($obsinresolution_query)) {
        delObsToResolution($obsid, $obsinresolution_result['restok_resolutionid']);
    }
}

/* Resolutions Functions */
function getResolutionIdByResolutionToken($resolution_token)
{
    global $db;
    
    $checkresolution_token_query = mysqli_query($db, "SELECT resolution_id FROM obs_resolutions WHERE resolution_token='" . $resolution_token . "' LIMIT 1");
    if (mysqli_num_rows($checkresolution_token_query) == 1) {
        $checkresolution_token_result = mysqli_fetch_array($checkresolution_token_query);
        return $checkresolution_token_result['resolution_id'];
    } else {
        return False;
    }
}

function updateResolution($fields, $resolutionid)
{
    global $db;
    $query_update = '';
    foreach ($fields as $key => $value) {
        if ($key == "resolution_comment" || $key == "resolution_time" || $key == "resolution_status") {
            $query_update .= "$key = '$value',";
        }
    }

    if(array_key_exists('resolution_status',$fields)) {
        if ($fields['resolution_status'] == 1) {
            $resolutiontime_query  = mysqli_query($db, "SELECT resolution_time FROM obs_resolutions WHERE resolution_id='" . $resolutionid . "' LIMIT 1");
            $resolutiontime_result = mysqli_fetch_array($resolutiontime_query);

            $resolutionobs = getResolutionObservations($resolutionid);
            $duplicateids = getDuplicateObsIdsInResolutions();
            $obsisduplicate = False;
            
            foreach($resolutionobs as $obsid) {
                if(in_array($obsid,$duplicateids)) {
                    $obsisduplicate = True;
                } 
            } 

            if ($resolutiontime_result['resolution_time'] == 0) {
              throw new Exception('La date doit être renseignée pour valider une resolution');
            }
            elseif($obsisduplicate) {
              throw new Exception('Une des observations existe dans plusieurs résolutions, impossible de valider la résolution');
            }
         }

         if ($fields['resolution_status'] == 1 || $fields['resolution_status'] == 0) {
             flushImagesCacheResolution($resolutionid);
         }
    }

    if (!empty($query_update)) {
        $query_update = substr($query_update, 0, -1);
        mysqli_query($db,"UPDATE obs_resolutions SET " . $query_update . " WHERE resolution_id = '" . $resolutionid . "'");
    }
    return True;
}

function addObsToResolution($obsid, $resolutionid)
{
    global $db;
    $query_obs = mysqli_query($db, "SELECT * FROM obs_resolutions_tokens WHERE restok_resolutionid='" . $resolutionid . "' AND restok_observationid='" . $obsid . "' LIMIT 1");
    if (mysqli_num_rows($query_obs) != 1) {
        mysqli_query($db, "INSERT INTO obs_resolutions_tokens (`restok_resolutionid`,`restok_observationid`) VALUES ('" . $resolutionid . "','" . $obsid . "')");
    }
}

function delObsToResolution($obsid, $resolutionid)
{
    global $db;
    mysqli_query($db, "DELETE FROM obs_resolutions_tokens WHERE restok_resolutionid='" . $resolutionid . "' AND restok_observationid='" . $obsid . "'");
    // Remove orphan resolutions
    $query_resolution = mysqli_query($db, "SELECT * FROM obs_resolutions_tokens WHERE restok_resolutionid='" . $resolutionid . "'");
    if (mysqli_num_rows($query_resolution) == 0) {
        mysqli_query($db, "DELETE FROM obs_resolutions WHERE resolution_id='" . $resolutionid . "'");
    }
}

function delResolution($resolutionid)
{
    global $db;
    $query_resolution = mysqli_query($db, "SELECT * FROM obs_resolutions_tokens WHERE restok_resolutionid='" . $resolutionid . "'");
    while ($result_resolution = mysqli_fetch_array($query_resolution)) {
        delObsToResolution($result_resolution['restok_observationid'], $resolutionid);
    }
}

function addResolution($fields, $obsidlist)
{
    global $db;
    if (count($obsidlist)) {
        mysqli_query($db, 'INSERT INTO obs_resolutions (
                                                                    `resolution_token`,
                                                                    `resolution_secretid`,
                                                                    `resolution_app_version`,
                                                                    `resolution_comment`,
                                                                    `resolution_time`,
                                                                    `resolution_status`)
                                                         VALUES ("' . $fields['resolution_token'] . '","' . $fields['resolution_secretid'] . '","' . $fields['resolution_app_version'] . '","' . $fields['resolution_comment'] . '","' . $fields['resolution_time'] . '","' . $fields['resolution_status'] . '")');
        $resolution_id = mysqli_insert_id($db);
        
        foreach ($obsidlist as $obs) {
            addObsToResolution($obs, $resolution_id);
        }
        
        return True;
    } else {
        return False;
    }
}

function isResolutionTokenWithSecretId($resolution_token, $secretid)
{
    global $db;
    $checkresolutiontoken_query = mysqli_query($db, "SELECT resolution_id FROM obs_resolutions WHERE resolution_token='" . $resolution_token . "' AND resolution_secretid='" . $secretid . "' LIMIT 1");
    if (mysqli_num_rows($checkresolutiontoken_query) == 1) {
        return True;
    } else {
        return False;
    }
}

function isResolutionTokenExists($resolution_token)
{
    global $db;
    $checkresolutiontoken_query = mysqli_query($db, "SELECT resolution_id FROM obs_resolutions WHERE resolution_token='" . $resolution_token . "' LIMIT 1");
    if (mysqli_num_rows($checkresolutiontoken_query) == 1) {
        return True;
    } else {
        return False;
    }
}


function getResolutionStatus($obsid)
{
    global $db;
    $resolution_status_query  = mysqli_query($db, "SELECT resolution_status 
                                                                                                                         FROM obs_resolutions 
                                                                                                                         LEFT JOIN obs_resolutions_tokens 
                                                                                                                         ON obs_resolutions.resolution_id = obs_resolutions_tokens.restok_resolutionid 
                                                                                                                         WHERE restok_observationid = '" . $obsid . "' LIMIT 1");
    $resolution_status_result = mysqli_fetch_array($resolution_status_query);
    $resolution_status        = ($resolution_status_result['resolution_status'] != null) ? $resolution_status_result['resolution_status'] : 0;
    return $resolution_status;
}

function getResolutionObservations($resolutionid) {
    global $db;
    $obslist = array();
    $obslist_query =  mysqli_query($db, "SELECT restok_observationid FROM obs_resolutions_tokens WHERE restok_resolutionid='".$resolutionid."'");
    while ($obslist_result = mysqli_fetch_array($obslist_query)) {
        $obsid = $obslist_result['restok_observationid'];
        $obslist[] = $obsid;
    }
    return $obslist;
}

function getDuplicateObsIdsInResolutions() {
    global $db;
    // Check duplicate observations in resolutions
    $obsduplicate_query = mysqli_query($db, "select restok_observationid,count(*) as nb from obs_resolutions_tokens group by restok_observationid");
    $duplicateobsids       = array();
    while ($obsduplicate_result = mysqli_fetch_array($obsduplicate_query)) {
        if ($obsduplicate_result['nb'] > 1) {
            $duplicateobsids[] = $obsduplicate_result['restok_observationid'];
        }
    }
    return $duplicateobsids;
}

function flushImagesCacheResolution($resolutionid)
{
    global $db;
    
    $obslist_query = mysqli_query($db, "SELECT obs_token FROM obs_resolutions_tokens LEFT JOIN obs_list ON obs_resolutions_tokens.restok_observationid = obs_list.obs_id WHERE restok_resolutionid='" . $resolutionid . "'");
    while ($obslist_result = mysqli_fetch_array($obslist_query)) {
        delete_token_cache($obslist_result['obs_token']);
    }
}
