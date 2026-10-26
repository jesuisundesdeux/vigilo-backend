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
 * type: text, textarea, datetime, urlbase, proto, language, timezone, charset, bool, int, url, choice (options)
 */

$settings_cards = array(
  'instance' => array('title' => 'Instance', 'icon' => 'house-gear'),
  'publication' => array('title' => 'Publication', 'icon' => 'megaphone'),
  'banniere' => array('title' => 'Bannière', 'icon' => 'info-circle'),
  'photos' => array('title' => 'Photos', 'icon' => 'image'),
  'antispam' => array('title' => 'Anti-spam', 'icon' => 'shield-check'),
  'problems' => array('title' => 'Suggestions de problèmes', 'icon' => 'diagram-3'),
);

$settings_fields = array(
  'vigilo_name' => array(
    'card' => 'instance', 'type' => 'text', 'label' => 'Nom de l\'instance', 'required' => true, 'default' => '',
    'help' => 'Nom affiché dans l\'application et l\'administration.'),
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
    'card' => 'publication', 'type' => 'choice', 'label' => 'Observations non modérées affichées au public', 'default' => '0',
    'options' => array(
      '0' => 'Pas d\'affichage du tout',
      'pixelated' => 'Affichées, photo pixelisée',
      'substitute' => 'Affichées, photo de substitution',
    ),
    'help' => 'Ce que le public voit des observations en attente de modération. « Pas d\'affichage » : elles n\'apparaissent qu\'une fois validées. « Photo pixelisée » : la vraie photo, pixelisée jusqu\'à la validation. « Photo de substitution » : une image générique à la place de la photo.'),
  'vigilo_shownonapproved_notext' => array(
    'card' => 'publication', 'type' => 'bool', 'label' => 'Masquer le texte des observations non modérées', 'default' => '0',
    'help' => 'Quand les observations non modérées sont affichées, masque leur commentaire et leur explication jusqu\'à la validation.'),
  'vigilo_resolved_hide_days' => array(
    'card' => 'publication', 'type' => 'int', 'label' => 'Masquer les observations résolues depuis plus de (jours)', 'default' => '0', 'suffix' => 'jours',
    'help' => 'Les observations résolues depuis plus de N jours ne sont plus listées publiquement. 0 = jamais masquées.'),

  // Announcement shown in the web app (get_banner.php). Empty message = no banner.
  'vigilo_banner_message' => array(
    'card' => 'banniere', 'type' => 'textarea', 'label' => 'Message', 'default' => '',
    'help' => 'Message affiché dans l\'application. Laisser vide pour ne pas afficher de bannière. Modifier le message le ré-affiche à tout le monde, même à ceux qui l\'avaient fermé.'),
  'vigilo_banner_type' => array(
    'card' => 'banniere', 'type' => 'choice', 'label' => 'Type', 'default' => 'info',
    'options' => array(
      'info' => 'Information (bleu)',
      'success' => 'Succès (vert)',
      'warning' => 'Attention (orange)',
      'alert' => 'Alerte (rouge)',
    ),
    'help' => 'Couleur et icône de la bannière selon l\'importance du message.'),
  'vigilo_banner_start' => array(
    'card' => 'banniere', 'type' => 'datetime', 'label' => 'Début (optionnel)', 'default' => '',
    'help' => 'Avant cette date et heure, la bannière n\'est pas affichée. Vide = dès maintenant.'),
  'vigilo_banner_end' => array(
    'card' => 'banniere', 'type' => 'datetime', 'label' => 'Fin (optionnel)', 'default' => '',
    'help' => 'Après cette date et heure, la bannière n\'est plus affichée. Vide = sans fin.'),
  'vigilo_banner_display' => array(
    'card' => 'banniere', 'type' => 'choice', 'label' => 'Affichage', 'default' => 'banner',
    'options' => array(
      'banner' => 'Bandeau sous la barre du haut',
      'modal' => 'Fenêtre au lancement',
      'both' => 'Les deux (bandeau + fenêtre)',
    ),
    'help' => 'Bandeau fermable sous la barre du haut, fenêtre ouverte au lancement, ou les deux. Dans tous les cas, une fois fermée, la bannière ne se rouvre pas jusqu\'au prochain message.'),


  'vigilo_blur_url' => array(
    'card' => 'photos', 'type' => 'url', 'label' => 'Serveur de floutage', 'default' => '', 'env' => 'VIGILO_BLUR_URL',
    'help' => 'Si renseigné, chaque photo envoyée (observations et résolutions) est d\'abord transmise à ce serveur, qui masque les visages et les plaques d\'immatriculation. Si le serveur ne répond pas, la photo est enregistrée telle qu\'envoyée (à vérifier lors de la modération, l\'incident est journalisé). Laisser vide pour publier les photos telles quelles. Exemple : http://blur:8000/blur (service « blur » du docker-compose).',
    'link' => array('https://github.com/jesuisundesdeux/vigilo-backend/tree/master/blur-server', 'Serveur de floutage Vigilo')),

  'vigilo_ratelimit_create' => array(
    'card' => 'antispam', 'type' => 'int', 'label' => 'Observations créées max. par IP et par 10 minutes', 'default' => '60',
    'help' => 'Au-delà, les créations depuis la même adresse IP sont refusées temporairement. 0 = pas de limite.'),

  'vigilo_problem_suggest_min' => array(
    'card' => 'problems', 'type' => 'int', 'label' => 'Nombre minimal d\'observations', 'default' => '3', 'suffix' => 'observations',
    'help' => 'Un groupe d\'observations publiées, sans problème, de même catégorie et proches est proposé comme problème à partir de ce nombre (2 au moins).'),
  'vigilo_problem_suggest_radius' => array(
    'card' => 'problems', 'type' => 'int', 'label' => 'Distance de regroupement', 'default' => '50', 'suffix' => 'mètres',
    'help' => 'Observations à moins de cette distance (ou dans la même rue) regroupées, comme pour les lieux récurrents des rapports (10 à 1 000 m).'),
  'vigilo_problem_suggest_months' => array(
    'card' => 'problems', 'type' => 'int', 'label' => 'Période analysée', 'default' => '12', 'suffix' => 'mois',
    'help' => 'Seules les observations de cette période sont analysées (1 à 120 mois).'),

);


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
      case 'choice':
        $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
        if (!array_key_exists($value, $options)) {
          $errors[$param] = 'Valeur non autorisée.';
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
      case 'url':
        if ($value !== '' && !preg_match('#^https?://[^\s"\'<>]+$#i', $value)) {
          $errors[$param] = 'L\'URL doit commencer par http:// ou https:// (ou être vide).';
        }
        break;
      case 'datetime':
        if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $value)) {
          $errors[$param] = 'Date et heure invalides.';
        }
        break;
      default:
        // Free text (text, textarea)
        break;
    }
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
$render_field = function ($param, $field, $value, $error) use ($timezones) {
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
      case 'choice':
        $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
        // Legacy value '1' of vigilo_shownonapproved means "pixelated"
        if (!array_key_exists($value, $options) && $value === '1' && array_key_exists('pixelated', $options)) {
          $value = 'pixelated';
        }
        $control = '<select class="form-select' . $invalid . '" id="' . h($id) . '" name="' . h($name) . '" aria-describedby="' . h($help_id) . '">';
        foreach ($options as $k => $label) {
          $control .= '<option value="' . h($k) . '"' . ($value === (string) $k ? ' selected' : '') . '>' . h($label) . '</option>';
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
      case 'int':
        $input = '<input type="number" min="0" step="1" class="form-control' . $invalid . '" id="' . h($id) . '" name="' . h($name) . '" value="' . h($value) . '" aria-describedby="' . h($help_id) . '" required>';
        if (!empty($field['suffix'])) {
          $control = '<div class="input-group" style="max-width: 16rem;">' . $input . '<span class="input-group-text">' . h($field['suffix']) . '</span></div>';
        } else {
          $control = '<div style="max-width: 16rem;">' . $input . '</div>';
        }
        break;
      case 'url':
        $env = !empty($field['env']) ? trim((string) getenv($field['env'])) : '';
        $control = '<input type="url" class="form-control' . $invalid . '" id="' . h($id) . '" name="' . h($name) . '" value="' . h($value) . '" placeholder="' . h($env !== '' ? $env : 'https://') . '" aria-describedby="' . h($help_id) . '">';
        if ($env !== '') {
          $control .= '<div class="form-text text-success"><i class="bi bi-info-circle"></i> Vide : la variable d\'environnement ' . h($field['env']) . ' est utilisée (' . h($env) . ').</div>';
        }
        break;
      case 'textarea':
        $control = '<textarea class="form-control' . $invalid . '" id="' . h($id) . '" name="' . h($name) . '" rows="3" aria-describedby="' . h($help_id) . '">' . h($value) . '</textarea>';
        break;
      case 'datetime':
        $control = '<input type="datetime-local" class="form-control' . $invalid . '" id="' . h($id) . '" name="' . h($name) . '" value="' . h($value) . '" aria-describedby="' . h($help_id) . '" style="max-width: 16rem;">';
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
