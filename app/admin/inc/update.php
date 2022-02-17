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

if (isset($config['SAAS_MODE']) && $config['SAAS_MODE']) {
    echo '<div class="alert alert-warning" role="alert">La configuration n\'est pas accessible en SaaS</div>';
} else {
    $data     = getWebContent("https://api.github.com/repos/jesuisundesdeux/vigilo-backend/tags");
    $git_json = json_decode($data, true);
    
    $biggest = '0.0.1';
    foreach ($git_json as $key => $value) {
        if (preg_match('/v([0-9]*).([0-9]*).([0-9]*)$/', $value['name'])) {
            $version = str_replace('v','',$value['name']);
            if (version_compare($version, $biggest, ">")) {
                $biggest = $value['name'];
            }
        }
    }
    
    $query_version  = mysqli_query($db, "SELECT config_value FROM obs_config WHERE config_param='vigilo_db_version' LIMIT 1");
    $result_version = mysqli_fetch_array($query_version);
    
    $code_version = BACKEND_VERSION;
    $db_version   = $result_version['config_value'];
    $last_version = $biggest;
    
    if ($code_version != $db_version) {
?>
 <div class="alert alert-danger" role="alert"><strong>Alerte !</strong> La version du code (<?= $code_version ?>) est différente de la version de la base (<?= $db_version ?>)</div>
  <?php
    }
    
    if ($code_version != $last_version) {
?>
 <div class="alert alert-info" role="alert">
    <strong>Nouvelle version disponible !</strong> Une nouvelle version (<?= $last_version ?>) est disponible, merci de faire la mise à jour dès que possible.<br />
    <a href="https://github.com/jesuisundesdeux/vigilo-backend/tree/<?= $last_version ?>">Rendez-vous sur Git-hub</a>
  </div>
  <?php
    }
?>
 <div class="table-responsive">
    <table class="table table-striped table-sm">
      <thead>
          <tr>
          <th>Version code</th>
          <th>Version base de données</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><?= $code_version ?></td>
          <td><?= $db_version ?></td>
        </tr>
      </tbody>
    </table>
  </div>
  <?php
    
    $migration_query       = mysqli_query($db, "SELECT config_value FROM obs_config WHERE config_param='migration_from_version' LIMIT 1");
    $migration_result      = mysqli_fetch_array($migration_query);
    $migration_fromversion = $migration_result['config_value'];
    
    // TODO chargé un script sql et le lancer à partir d'ici / si fichier ci dessous existe c'est pour migration/post operation
    if (file_exists('./inc/updates/' . $migration_fromversion . '.php')) {
        echo "<h2>Migration necessaire</h2>";
        require_once('./inc/updates/' . $migration_fromversion . '.php');
    }
}
