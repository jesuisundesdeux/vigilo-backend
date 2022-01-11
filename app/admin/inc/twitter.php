<?php
/*
Copyright (C) 2020 Velocité Montpellier

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

if (!isset($page_name) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $menu[$page_name]['access'])) {
    exit('Not allowed');
}


if (isset($_GET['action']) && !isset($_POST['ta_id'])) {
    if ($_GET['action'] == 'add') {
        mysqli_query($db, "INSERT INTO obs_twitteraccounts (ta_consumer,
                                                       ta_consumersecret,
                                                       ta_accesstoken,
                                                       ta_accesstokensecret)
                                               VALUES ('consumer',
                                                       'consumerkey',
                                                       'accesstoken',
                                                       'accesstokensecret')");
        echo '<div class="alert alert-success" role="alert">Compte Twitter ajouté, merci de remplir les champs correspondants</div>';
    }
    if (isset($_GET['taid']) && is_numeric($_GET['taid'])) {
        if ($_GET['action'] == 'delete') {
            $taid = mysqli_real_escape_string($db, $_GET['taid']);
            mysqli_query($db, "DELETE FROM obs_twitteraccounts WHERE ta_id = '" . $taid . "'");
            echo '<div class="alert alert-success" role="alert">Compte twitter <strong>' . $taid . '</strong> supprimé</div>';
        }
        elseif ($_GET['action'] == 'test') {  // test l'envoi des tweets et poste le succès ou le code d'erreur

	      	$taid = mysqli_real_escape_string($db,$_GET['taid']);
	      	$query_ta = mysqli_query($db, "SELECT * FROM obs_twitteraccounts WHERE ta_id='".$taid."' LIMIT 1");
	      	$result_ta = mysqli_fetch_array($query_ta) ;
	      	
	      	if (!empty($result_ta['ta_consumer']) && !empty($result_ta['ta_consumersecret']) && !empty($result_ta['ta_accesstoken']) && !empty($result_ta['ta_accesstokensecret'])) {
	      	
	      		$twitter_ids   = array(
				"consumer" => $result_ta['ta_consumer'],
				"consumersecret" => $result_ta['ta_consumersecret'],
				"accesstoken" => $result_ta['ta_accesstoken'],
				"accesstokensecret" => $result_ta['ta_accesstokensecret']
			);
	      	
	      		// on vérifie la bibliothèque
	      		require_once('../lib/codebird-php/codebird.php');
	      		
	      		// on attribue un identifiant au test - les tweets similaires sont refusés
	      		$twtId = rand(1000,9999) ;
	      		$twtText = 'Ceci est un test automatique envoyé par le back-end vigilo '.$twtId ;
			$twtRet = tweet($twitter_ids, $twtText) ;

	      		// reply est un objet avec beaucoup d'informations, on récupère le httpstatus
	      		if ( $twtRet->httpstatus == "200" ) {
	      			echo '<div class="alert alert-success" role="alert">Vérifier le <a target="\blank" href="https://twitter.com/'.$twtRet->user->screen_name.'">twitt</a> du compte n° <strong>'.$taid.'</strong> (n° de vérification '.$twtId.')</div>';
	      		}
	      		else {
	      			echo '<div class="alert alert-warning" role="alert">Vérifier le code de l\'erreur n° <strong>'.$twtRet->httpstatus.'</strong> (<a href="https://developer.twitter.com/ja/docs/basics/response-codes" target="_blank">liste des erreurs</a>)</div>';
	      		}
	      	}
	      	else {
	      		echo '<div class="alert alert-warning" role="alert">Les clés sont incomplètes pour le compte twitter n° <strong>'.$taid.'</strong></div>';
	      	}
    	} // fin du elseif de test du twitt
    }
}

if (isset($_POST['ta_id'])) {
    $update = "";
    foreach ($_POST as $key => $value) {
        if (preg_match('/ta_(?:.*)$/', $key)) {
            $key   = mysqli_real_escape_string($db, $key);
            $value = mysqli_real_escape_string($db, $value);
            $update .= $key . "='" . $value . "',";
        }
    }
    $update = rtrim($update, ',');
    $taid   = mysqli_real_escape_string($db, $_POST['ta_id']);
    mysqli_query($db, "UPDATE obs_twitteraccounts SET " . $update . " WHERE ta_id='" . $taid . "'");
    
    echo '<div class="alert alert-success" role="alert">Compte Twitter <strong>' . $taid . '</strong> mis à jour</div>';
}

$query_ta = mysqli_query($db, "SELECT * FROM obs_twitteraccounts");

?>

<h2>Liste</h2>
<p><a href="?page=<?= $page_name ?>&action=add">Ajouter un compte Twitter</a></p>

<div class="table-responsive">
  <table class="table table-striped table-sm">
    <thead>
      <tr>
        <th># ID</th>
        <th>Consumer</th>
        <th>Consumer Secret</th>
        <th>Access Token</th>
        <th>Access Token Secret</th>
        <th> </th>
        <th> </th>
      </tr>
    </thead>
    <tbody>
<?php
while ($result_ta = mysqli_fetch_array($query_ta)) {
?>
     <form action="" method="POST">
      <tr>
        <td>#<?= $result_ta['ta_id'] ?></td>
        <td>
          <input type="text" class="form-control-plaintext" name="ta_consumer" value="<?= $result_ta['ta_consumer'] ?>" required />
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="ta_consumersecret" value="<?= $result_ta['ta_consumersecret'] ?>" required />
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="ta_accesstoken" value="<?= $result_ta['ta_accesstoken'] ?>" required />
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="ta_accesstokensecret" value="<?= $result_ta['ta_accesstokensecret'] ?>" required />
        </td>
        <td>
          <input type="hidden" name="ta_id" value="<?= $result_ta['ta_id'] ?>" />
          <button class="btn btn-primary" type="submit">Valider édition</button>
        </td>
        <td>
          <a href="?page=<?=$page_name ?>&action=test&taid=<?=$result_ta['ta_id'] ?>" onclick="return confirm('Valider le test ? Ceci enverra un twitt public sur votre compte...')"><span data-feather="twitter"></span> Tester</a>
          <br/>
          <a href="?page=<?=$page_name ?>&action=delete&taid=<?=$result_ta['ta_id'] ?>" onclick="return confirm('Merci de valider la suppression')"><span data-feather="x"></span> Supprimer</a>
        </td>
      </tr>
      </form>
<?php
}
?>
   </tbody>
  </table>
</div>
<br />
