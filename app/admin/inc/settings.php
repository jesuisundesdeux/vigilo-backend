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
  echo '<div class="alert alert-warning" role="alert"><i class="bi bi-cloud-slash"></i> La configuration n\'est pas accessible en SaaS</div>';
  return;
}

/*
 * Editable settings. Only these parameters can be written from the UI
 * (vigilo_db_version, in particular, is never editable).
 * type: text, urlbase, proto, language, timezone, charset, bool, int, panel,
 *       provider, tiles, url, secret
 */
$default_tiles_url = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

$settings_cards = array(
  'instance' => array('title' => 'Instance', 'icon' => 'house-gear'),
  'publication' => array('title' => 'Publication', 'icon' => 'megaphone'),
  'map' => array('title' => 'Carte', 'icon' => 'map'),
  'photos' => array('title' => 'Photos', 'icon' => 'image'),
  'antispam' => array('title' => 'Anti-spam', 'icon' => 'shield-check'),
  'twitter' => array('title' => 'Twitter', 'icon' => 'twitter-x'),
);

$settings_fields = array(
  'vigilo_name' => array(
    'card' => 'instance', 'type' => 'text', 'label' => 'Nom de l\'instance', 'required' => true, 'default' => '',
    'help' => 'Nom affiché dans l\'application, l\'administration et sur les panneaux.'),
  'vigilo_urlbase' => array(
    'card' => 'instance', 'type' => 'urlbase', 'label' => 'URL de base', 'required' => true, 'default' => '',
    'help' => 'Nom de domaine (et chemin éventuel) du backend, sans protocole. Exemple : vigilo.example.org'),
  'vigilo_http_proto' => array(
    'card' => 'instance', 'type' => 'proto', 'label' => 'Protocole', 'required' => true, 'default' => 'https',
    'help' => 'Protocole utilisé pour construire les liens publics. https est fortement recommandé.'),
  'vigilo_language' => array(
    'card' => 'instance', 'type' => 'language', 'label' => 'Langue', 'required' => true, 'default' => 'fr-FR',
    'help' => 'Code de langue de l\'instance, par exemple fr-FR.'),
  'vigilo_timezone' => array(
    'card' => 'instance', 'type' => 'timezone', 'label' => 'Fuseau horaire', 'required' => true, 'default' => 'Europe/Paris',
    'help' => 'Fuseau utilisé pour les dates des observations.'),
  'mysql_charset' => array(
    'card' => 'instance', 'type' => 'charset', 'label' => 'Jeu de caractères MySQL', 'required' => true, 'default' => 'utf8mb4',
    'help' => 'Jeu de caractères de la connexion à la base. À ne modifier qu\'en connaissance de cause.'),

  'vigilo_shownonapproved' => array(
    'card' => 'publication', 'type' => 'bool', 'label' => 'Afficher les observations non modérées', 'default' => '0',
    'help' => 'Si coché, les observations sont publiées sans attendre la validation d\'un modérateur.'),
  'vigilo_resolved_hide_days' => array(
    'card' => 'publication', 'type' => 'int', 'label' => 'Masquer les observations résolues depuis plus de (jours)', 'default' => '0', 'suffix' => 'jours',
    'help' => 'Les observations résolues depuis plus de N jours ne sont plus listées publiquement. 0 = jamais masquées.'),
  'vigilo_panel' => array(
    'card' => 'publication', 'type' => 'panel', 'label' => 'Panneau', 'required' => true, 'default' => '',
    'help' => 'Modèle graphique utilisé pour générer les images des observations (dossier panels/).'),

  'vigilo_map_provider' => array(
    'card' => 'map', 'type' => 'provider', 'label' => 'Fournisseur de carte', 'default' => 'auto',
    'help' => 'Automatique : MapQuest si une clé est renseignée, OpenStreetMap sinon.'),
  'vigilo_map_tiles_url' => array(
    'card' => 'map', 'type' => 'tiles', 'label' => 'URL des tuiles OpenStreetMap', 'default' => $default_tiles_url,
    'help' => 'Modèle d\'URL des tuiles, avec {z}, {x} et {y}. Laisser vide pour le serveur OpenStreetMap par défaut ; respectez sa politique d\'utilisation.'),
  'vigilo_mapquest_api' => array(
    'card' => 'map', 'type' => 'secret', 'label' => 'Clé API MapQuest', 'default' => '',
    'help' => 'Nécessaire uniquement pour le fournisseur MapQuest.'),

  'sgblur_url' => array(
    'card' => 'photos', 'type' => 'url', 'label' => 'URL du service SGBlur', 'default' => '',
    'help' => 'Si renseignée, les photos envoyées sont transmises à ce service pour flouter automatiquement visages et plaques d\'immatriculation. Laisser vide pour désactiver.',
    'link' => array('https://github.com/cquest/sgblur', 'Projet SGBlur')),

  'vigilo_ratelimit_create' => array(
    'card' => 'antispam', 'type' => 'int', 'label' => 'Observations créées max. par IP et par 10 minutes', 'default' => '60',
    'help' => 'Au-delà, les créations depuis la même adresse IP sont refusées temporairement. 0 = pas de limite.'),

  'twitter_expiry_time' => array(
    'card' => 'twitter', 'type' => 'int', 'label' => 'Délai max. pour tweeter une observation', 'default' => '0', 'suffix' => 'heures',
    'help' => 'Nombre d\'heures maximum après sa création pendant lequel une observation peut encore être tweetée.'),
);

/* Available panels (directories in ../panels) */
$available_panels = array();
if (is_dir('../panels') && ($panels_handle = opendir('../panels'))) {
  while (false !== ($entry = readdir($panels_handle))) {
    if ($entry !== '.' && $entry !== '..' && $entry[0] !== '.' && is_dir('../panels/' . $entry)) {
      $available_panels[] = $entry;
    }
  }
  closedir($panels_handle);
  sort($available_panels);
}

$timezones = timezone_identifiers_list();

/* Current values */
$current = array();
foreach ($settings_fields as $param => $field) {
  $current[$param] = $field['default'];
}
$query_config = mysqli_query($db, 'SELECT config_param, config_value FROM obs_config');
if ($query_config) {
  while ($row = mysqli_fetch_assoc($query_config)) {
    if (array_key_exists($row['config_param'], $settings_fields)) {
      $current[$row['config_param']] = (string) $row['config_value'];
    }
  }
}

$values = $current;
$errors = array();
$saved = false;
$changed = array();

if (isset($_POST['settings_save'])) {
  $posted = (isset($_POST['cfg']) && is_array($_POST['cfg'])) ? $_POST['cfg'] : array();

  foreach ($settings_fields as $param => $field) {
    if ($field['type'] === 'bool') {
      $values[$param] = !empty($posted[$param]) ? '1' : '0';
      continue;
    }
    if (!isset($posted[$param])) {
      // Field absent from the form: keep the current value
      continue;
    }
    if (!is_string($posted[$param])) {
      $errors[$param] = 'Valeur invalide.';
      continue;
    }
    $value = trim($posted[$param]);
    $values[$param] = $value;

    if ($value === '' && !empty($field['required'])) {
      $errors[$param] = 'Ce champ est obligatoire.';
      continue;
    }
    if (strlen($value) > 1000) {
      $errors[$param] = 'Valeur trop longue.';
      continue;
    }

    switch ($field['type']) {
      case 'int':
        if (!preg_match('/^[0-9]{1,9}$/', $value)) {
          $errors[$param] = 'Nombre entier positif ou nul attendu.';
        } else {
          $values[$param] = (string) intval($value);
        }
        break;
      case 'proto':
        $value = strtolower($value);
        $values[$param] = $value;
        if (!in_array($value, array('http', 'https'), true)) {
          $errors[$param] = 'Valeur attendue : http ou https.';
        }
        break;
      case 'provider':
        if (!in_array($value, array('auto', 'osm', 'mapquest'), true)) {
          $errors[$param] = 'Valeur attendue : auto, osm ou mapquest.';
        }
        break;
      case 'urlbase':
        $value = rtrim($value, '/');
        $values[$param] = $value;
        if (strpos($value, '://') !== false) {
          $errors[$param] = 'Indiquer le domaine sans http:// ni https:// (le protocole est un paramètre séparé).';
        } elseif (!preg_match('#^[A-Za-z0-9.\-\[\]:]+(/[A-Za-z0-9._~\-/%]*)?$#', $value)) {
          $errors[$param] = 'Domaine invalide. Exemple : vigilo.example.org';
        }
        break;
      case 'language':
        if (!preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $value)) {
          $errors[$param] = 'Code de langue invalide. Exemple : fr-FR';
        }
        break;
      case 'timezone':
        if (!in_array($value, $timezones, true)) {
          $errors[$param] = 'Fuseau horaire inconnu.';
        }
        break;
      case 'charset':
        if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $value)) {
          $errors[$param] = 'Jeu de caractères invalide.';
        }
        break;
      case 'panel':
        if (!in_array($value, $available_panels, true)) {
          $errors[$param] = 'Panneau inconnu.';
        }
        break;
      case 'url':
        if ($value !== '' && !preg_match('#^https?://[^\s"\'<>]+$#i', $value)) {
          $errors[$param] = 'L\'URL doit commencer par http:// ou https:// (ou être vide).';
        }
        break;
      case 'tiles':
        if ($value === '') {
          $value = $default_tiles_url;
          $values[$param] = $value;
        }
        if (!preg_match('#^https?://[^\s"\'<>]+$#i', $value)) {
          $errors[$param] = 'L\'URL doit commencer par http:// ou https://.';
        } elseif (strpos($value, '{z}') === false || strpos($value, '{x}') === false || strpos($value, '{y}') === false) {
          $errors[$param] = 'L\'URL doit contenir {z}, {x} et {y}.';
        }
        break;
      case 'secret':
        if (preg_match('/\s/', $value)) {
          $errors[$param] = 'La clé ne doit pas contenir d\'espace.';
        }
        break;
      default:
        // Free text
        break;
    }
  }

  if (empty($errors) && $values['vigilo_map_provider'] === 'mapquest' && $values['vigilo_mapquest_api'] === '') {
    $errors['vigilo_mapquest_api'] = 'Une clé MapQuest est nécessaire avec le fournisseur MapQuest.';
  }

  if (empty($errors)) {
    $db_error = false;
    foreach ($settings_fields as $param => $field) {
      if ((string) $values[$param] === (string) $current[$param]) {
        continue;
      }
      // $param comes from the allowlist above, never from the request
      $sql = "INSERT INTO obs_config (config_param, config_value)
              VALUES ('" . mysqli_real_escape_string($db, $param) . "', '" . mysqli_real_escape_string($db, $values[$param]) . "')
              ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)";
      if (mysqli_query($db, $sql)) {
        $changed[$param] = ($field['type'] === 'secret') ? ($values[$param] === '' ? '(vide)' : '********') : $values[$param];
      } else {
        $db_error = true;
      }
    }
    if (!empty($changed)) {
      audit_log('settings_edit', '', $changed);
    }
    if ($db_error) {
      echo '<div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle"></i> Erreur lors de l\'enregistrement d\'une partie de la configuration.</div>';
    } else {
      $saved = true;
    }
    $current = $values;
  }
}

if ($saved) {
  if (empty($changed)) {
    echo '<div class="alert alert-info alert-dismissible fade show" role="alert"><i class="bi bi-info-circle"></i> Aucune modification.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button></div>';
  } else {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="bi bi-check-circle"></i> Configuration mise à jour (' . h(count($changed)) . ' paramètre' . (count($changed) > 1 ? 's' : '') . ' modifié' . (count($changed) > 1 ? 's' : '') . ').<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button></div>';
  }
}
if (!empty($errors)) {
  echo '<div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle"></i> La configuration n\'a pas été enregistrée, merci de corriger :<ul class="mb-0">';
  foreach ($errors as $param => $message) {
    echo '<li><strong>' . h($settings_fields[$param]['label']) . '</strong> : ' . h($message) . '</li>';
  }
  echo '</ul></div>';
}

/* Rendering of one field */
$render_field = function ($param, $field, $value, $error) use ($available_panels, $timezones) {
  $id = 'cfg_' . $param;
  $name = 'cfg[' . $param . ']';
  $invalid = ($error !== null) ? ' is-invalid' : '';
  $help_id = $id . '_help';
  $required = !empty($field['required']) ? ' required' : '';
  $html = '<div class="mb-3">';

  if ($field['type'] === 'bool') {
    $html .= '<div class="form-check form-switch">';
    $html .= '<input class="form-check-input' . $invalid . '" type="checkbox" role="switch" id="' . h($id) . '" name="' . h($name) . '" value="1"' . ($value === '1' ? ' checked' : '') . ' aria-describedby="' . h($help_id) . '">';
    $html .= '<label class="form-check-label" for="' . h($id) . '">' . h($field['label']) . '</label>';
    $html .= '</div>';
  } else {
    $html .= '<label class="form-label" for="' . h($id) . '">' . h($field['label']) . '</label>';
    $control = '';
    switch ($field['type']) {
      case 'proto':
        $control = '<select class="form-select' . $invalid . '" id="' . h($id) . '" name="' . h($name) . '" aria-describedby="' . h($help_id) . '">';
        foreach (array('https' => 'https', 'http' => 'http') as $k => $label) {
          $control .= '<option value="' . h($k) . '"' . ($value === $k ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        $control .= '</select>';
        break;
      case 'provider':
        $control = '<select class="form-select' . $invalid . '" id="' . h($id) . '" name="' . h($name) . '" aria-describedby="' . h($help_id) . '">';
        foreach (array('auto' => 'Automatique', 'osm' => 'OpenStreetMap', 'mapquest' => 'MapQuest') as $k => $label) {
          $control .= '<option value="' . h($k) . '"' . ($value === $k ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        $control .= '</select>';
        break;
      case 'timezone':
        $control = '<select class="form-select' . $invalid . '" id="' . h($id) . '" name="' . h($name) . '" aria-describedby="' . h($help_id) . '"' . $required . '>';
        if (!in_array($value, $timezones, true)) {
          $control .= '<option value="' . h($value) . '" selected>' . h($value === '' ? '— choisir —' : $value . ' (invalide)') . '</option>';
        }
        foreach ($timezones as $tz) {
          $control .= '<option value="' . h($tz) . '"' . ($value === $tz ? ' selected' : '') . '>' . h($tz) . '</option>';
        }
        $control .= '</select>';
        break;
      case 'panel':
        $control = '<select class="form-select' . $invalid . '" id="' . h($id) . '" name="' . h($name) . '" aria-describedby="' . h($help_id) . '"' . $required . '>';
        if (!in_array($value, $available_panels, true)) {
          $control .= '<option value="' . h($value) . '" selected>' . h($value === '' ? '— choisir —' : $value . ' (introuvable)') . '</option>';
        }
        foreach ($available_panels as $panel) {
          $control .= '<option value="' . h($panel) . '"' . ($value === $panel ? ' selected' : '') . '>' . h($panel) . '</option>';
        }
        $control .= '</select>';
        break;
      case 'secret':
        $control = '<div class="input-group">';
        $control .= '<input type="password" class="form-control font-monospace' . $invalid . '" id="' . h($id) . '" name="' . h($name) . '" value="' . h($value) . '" autocomplete="off" spellcheck="false" aria-describedby="' . h($help_id) . '">';
        $control .= '<button class="btn btn-outline-secondary" type="button" data-secret-toggle="' . h($id) . '" title="Afficher / masquer" aria-label="Afficher / masquer"><i class="bi bi-eye"></i></button>';
        $control .= '</div>';
        break;
      case 'int':
        $input = '<input type="number" min="0" step="1" class="form-control' . $invalid . '" id="' . h($id) . '" name="' . h($name) . '" value="' . h($value) . '" aria-describedby="' . h($help_id) . '" required>';
        if (!empty($field['suffix'])) {
          $control = '<div class="input-group" style="max-width: 16rem;">' . $input . '<span class="input-group-text">' . h($field['suffix']) . '</span></div>';
        } else {
          $control = '<div style="max-width: 16rem;">' . $input . '</div>';
        }
        break;
      case 'url':
      case 'tiles':
        $control = '<input type="url" class="form-control' . $invalid . '" id="' . h($id) . '" name="' . h($name) . '" value="' . h($value) . '" placeholder="https://" aria-describedby="' . h($help_id) . '">';
        break;
      default:
        $control = '<input type="text" class="form-control' . $invalid . '" id="' . h($id) . '" name="' . h($name) . '" value="' . h($value) . '" aria-describedby="' . h($help_id) . '"' . $required . '>';
        break;
    }
    $html .= $control;
  }

  if ($error !== null) {
    $html .= '<div class="invalid-feedback d-block">' . h($error) . '</div>';
  }
  $html .= '<div class="form-text" id="' . h($help_id) . '">' . h($field['help']);
  if (!empty($field['link'])) {
    $html .= ' <a href="' . h($field['link'][0]) . '" target="_blank" rel="noopener noreferrer">' . h($field['link'][1]) . ' <i class="bi bi-box-arrow-up-right"></i></a>';
  }
  $html .= '</div></div>';
  return $html;
};
?>

<form action="?page=<?= h($page_name) ?>" method="POST" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="settings_save" value="1" />

  <div class="row g-4">
<?php
foreach ($settings_cards as $card_key => $card) {
  ?>
    <div class="col-12 col-xl-6">
      <div class="card h-100 shadow-sm">
        <div class="card-header fw-semibold"><i class="bi bi-<?= h($card['icon']) ?> me-1"></i> <?= h($card['title']) ?></div>
        <div class="card-body">
<?php
  if ($card_key === 'twitter') {
    echo '<p class="small text-body-secondary">L\'API Twitter/X a changé : la publication automatique peut ne plus fonctionner.</p>';
  }
  foreach ($settings_fields as $param => $field) {
    if ($field['card'] !== $card_key) {
      continue;
    }
    echo $render_field($param, $field, (string) $values[$param], isset($errors[$param]) ? $errors[$param] : null);
  }
  ?>
        </div>
      </div>
    </div>
<?php
}
?>
  </div>

  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Enregistrer</button>
    <a class="btn btn-outline-secondary" href="?page=<?= h($page_name) ?>">Annuler</a>
  </div>
</form>

<script>
  (function () {
    document.querySelectorAll('[data-secret-toggle]').forEach(function (button) {
      button.addEventListener('click', function () {
        var input = document.getElementById(button.getAttribute('data-secret-toggle'));
        if (!input) { return; }
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        var icon = button.querySelector('i');
        if (icon) { icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye'; }
      });
    });
  })();
</script>
