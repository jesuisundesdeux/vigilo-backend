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

/* Editable columns of obs_twitteraccounts (allowlist) => label */
$ta_columns = array(
    'ta_consumer' => 'Consumer key',
    'ta_consumersecret' => 'Consumer secret',
    'ta_accesstoken' => 'Access token',
    'ta_accesstokensecret' => 'Access token secret',
);

/* Only the last 4 characters of a credential are displayed */
$ta_mask = function ($value) {
    $value = (string) $value;
    if ($value === '') {
        return '';
    }
    if (strlen($value) <= 4) {
        return str_repeat('•', strlen($value));
    }
    return '••••' . substr($value, -4);
};

$ta_alert = function ($type, $message_html) {
    echo '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">' . $message_html
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button></div>';
};

echo '<div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-octagon"></i> <strong>Fonctionnalité dépréciée !</strong> Cette fonctionnalité sera supprimée dans les prochaines versions.</div>';
echo '<div class="alert alert-info" role="alert"><i class="bi bi-info-circle"></i> L\'API de Twitter/X a changé (accès payant, nouvelles règles) : la publication des observations peut ne plus fonctionner, même avec des clés valides.</div>';

/* Action links (CSRF checked by index.php) */
if (isset($_GET['action'], $_GET['taid']) && ctype_digit((string) $_GET['taid'])) {
    $taid = (int) $_GET['taid'];

    if ($_GET['action'] === 'delete') {
        if (mysqli_query($db, "DELETE FROM obs_twitteraccounts WHERE ta_id = " . $taid) && mysqli_affected_rows($db) > 0) {
            audit_log('twitter_account_delete', (string) $taid);
            $ta_alert('success', 'Compte Twitter <strong>#' . h($taid) . '</strong> supprimé.');
        } else {
            $ta_alert('warning', 'Compte Twitter <strong>#' . h($taid) . '</strong> introuvable.');
        }
    } elseif ($_GET['action'] === 'test') {
        // Posts a test tweet and shows the result
        $query_ta = mysqli_query($db, "SELECT * FROM obs_twitteraccounts WHERE ta_id = " . $taid . " LIMIT 1");
        $result_ta = $query_ta ? mysqli_fetch_assoc($query_ta) : null;

        if ($result_ta && $result_ta['ta_consumer'] !== '' && $result_ta['ta_consumersecret'] !== ''
            && $result_ta['ta_accesstoken'] !== '' && $result_ta['ta_accesstokensecret'] !== '') {
            $twitter_ids = array(
                'consumer' => $result_ta['ta_consumer'],
                'consumersecret' => $result_ta['ta_consumersecret'],
                'accesstoken' => $result_ta['ta_accesstoken'],
                'accesstokensecret' => $result_ta['ta_accesstokensecret'],
            );

            require_once('../lib/codebird-php/codebird.php');

            // Identical tweets are refused: add a random number
            $twt_id = mt_rand(1000, 9999);
            $twt_ret = null;
            try {
                $twt_ret = tweet($twitter_ids, 'Ceci est un test automatique envoyé par le back-end vigilo ' . $twt_id);
            } catch (Throwable $e) {
                $twt_ret = null;
            }
            $twt_status = (is_object($twt_ret) && isset($twt_ret->httpstatus)) ? (string) $twt_ret->httpstatus : '';

            if ($twt_status === '200') {
                $screen_name = (isset($twt_ret->user) && isset($twt_ret->user->screen_name)) ? (string) $twt_ret->user->screen_name : '';
                $link = $screen_name !== ''
                    ? '<a target="_blank" rel="noopener noreferrer" href="https://x.com/' . h(rawurlencode($screen_name)) . '">tweet</a>'
                    : 'tweet';
                $ta_alert('success', 'Vérifier le ' . $link . ' du compte <strong>#' . h($taid) . '</strong> (n° de vérification ' . h($twt_id) . ').');
            } else {
                $ta_alert('warning', 'Échec de l\'envoi pour le compte <strong>#' . h($taid) . '</strong>'
                    . ($twt_status !== '' ? ' : code HTTP <strong>' . h($twt_status) . '</strong>' : '')
                    . ' (<a href="https://developer.x.com/en/support/x-api/error-troubleshooting" target="_blank" rel="noopener noreferrer">liste des erreurs</a>).');
            }
        } elseif ($result_ta) {
            $ta_alert('warning', 'Les clés sont incomplètes pour le compte Twitter <strong>#' . h($taid) . '</strong>.');
        } else {
            $ta_alert('warning', 'Compte Twitter <strong>#' . h($taid) . '</strong> introuvable.');
        }
    }
}

/* Forms */
$ta_form_errors = array();
$ta_form_values = array();
if (isset($_POST['ta_form']) && in_array($_POST['ta_form'], array('create', 'edit'), true)) {
    $is_create = ($_POST['ta_form'] === 'create');
    $taid = 0;
    if (!$is_create) {
        $taid = (isset($_POST['ta_id']) && ctype_digit((string) $_POST['ta_id'])) ? (int) $_POST['ta_id'] : 0;
        if ($taid <= 0) {
            $ta_form_errors[] = 'Compte invalide.';
        }
    }

    $set = array();
    foreach ($ta_columns as $column => $label) {
        $value = (isset($_POST[$column]) && is_string($_POST[$column])) ? trim($_POST[$column]) : '';
        if ($value === '') {
            if ($is_create) {
                $ta_form_errors[] = $label . ' : champ obligatoire.';
            }
            // Edit: empty field = keep the current value
            continue;
        }
        if (strlen($value) > 255 || preg_match('/\s/', $value)) {
            $ta_form_errors[] = $label . ' : valeur invalide (255 caractères max., sans espace).';
            continue;
        }
        if ($is_create) {
            $ta_form_values[$column] = $value;
        }
        // $column comes from the allowlist, never from the request
        $set[$column] = "'" . mysqli_real_escape_string($db, $value) . "'";
    }

    if (empty($ta_form_errors)) {
        if ($is_create) {
            $sql = "INSERT INTO obs_twitteraccounts (" . implode(', ', array_keys($set)) . ") VALUES (" . implode(', ', $set) . ")";
            if (mysqli_query($db, $sql)) {
                $new_id = mysqli_insert_id($db);
                audit_log('twitter_account_create', (string) $new_id);
                $ta_form_values = array();
                $ta_alert('success', 'Compte Twitter <strong>#' . h($new_id) . '</strong> ajouté.');
            } else {
                $ta_alert('danger', 'Erreur lors de l\'ajout du compte Twitter.');
            }
        } elseif (empty($set)) {
            $ta_alert('info', 'Aucune modification pour le compte Twitter <strong>#' . h($taid) . '</strong>.');
        } else {
            $assignments = array();
            foreach ($set as $column => $sql_value) {
                $assignments[] = $column . ' = ' . $sql_value;
            }
            if (mysqli_query($db, "UPDATE obs_twitteraccounts SET " . implode(', ', $assignments) . " WHERE ta_id = " . $taid)) {
                audit_log('twitter_account_edit', (string) $taid, array('fields' => array_keys($set)));
                $ta_alert('success', 'Compte Twitter <strong>#' . h($taid) . '</strong> mis à jour.');
            } else {
                $ta_alert('danger', 'Erreur lors de la mise à jour du compte Twitter <strong>#' . h($taid) . '</strong>.');
            }
        }
    } else {
        $html = 'Le compte n\'a pas été enregistré :<ul class="mb-0">';
        foreach ($ta_form_errors as $message) {
            $html .= '<li>' . h($message) . '</li>';
        }
        $ta_alert('danger', $html . '</ul>');
    }
}

$accounts = array();
$query_ta = mysqli_query($db, "SELECT ta_id, ta_consumer, ta_consumersecret, ta_accesstoken, ta_accesstokensecret FROM obs_twitteraccounts ORDER BY ta_id");
if ($query_ta) {
    while ($row = mysqli_fetch_assoc($query_ta)) {
        $accounts[] = $row;
    }
}
$show_create = !empty($ta_form_errors) && isset($_POST['ta_form']) && $_POST['ta_form'] === 'create';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h2 class="h4 mb-0">Comptes Twitter</h2>
  <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#ta-create" aria-expanded="<?= $show_create ? 'true' : 'false' ?>" aria-controls="ta-create">
    <i class="bi bi-plus-lg"></i> Ajouter un compte
  </button>
</div>

<div class="collapse<?= $show_create ? ' show' : '' ?>" id="ta-create">
  <div class="card card-body mb-4 shadow-sm">
    <form action="?page=<?= h($page_name) ?>" method="POST" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="ta_form" value="create" />
      <div class="row g-3">
<?php foreach ($ta_columns as $column => $label) { ?>
        <div class="col-12 col-md-6">
          <label class="form-label" for="create_<?= h($column) ?>"><?= h($label) ?></label>
          <input type="<?= $column === 'ta_consumer' ? 'text' : 'password' ?>" class="form-control font-monospace" id="create_<?= h($column) ?>" name="<?= h($column) ?>" value="<?= h(isset($ta_form_values[$column]) ? $ta_form_values[$column] : '') ?>" maxlength="255" autocomplete="off" spellcheck="false" required />
        </div>
<?php } ?>
      </div>
      <div class="form-text mb-3">Clés fournies par le portail développeur de X (developer.x.com).</div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Ajouter</button>
    </form>
  </div>
</div>

<?php if (empty($accounts)) { ?>
<div class="alert alert-secondary" role="alert">Aucun compte Twitter configuré.</div>
<?php } else { ?>
<div class="table-responsive">
  <table class="table table-striped table-sm table-admin align-middle">
    <thead>
      <tr>
        <th scope="col">#</th>
<?php foreach ($ta_columns as $label) { ?>
        <th scope="col"><?= h($label) ?></th>
<?php } ?>
        <th scope="col" class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
<?php
foreach ($accounts as $account) {
    $id = (int) $account['ta_id'];
    ?>
      <tr>
        <td>#<?= h($id) ?></td>
<?php foreach ($ta_columns as $column => $label) { ?>
        <td class="font-monospace"><?= $account[$column] === '' ? '<span class="text-body-secondary">—</span>' : h($ta_mask($account[$column])) ?></td>
<?php } ?>
        <td class="text-end text-nowrap">
          <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#ta-edit-<?= h($id) ?>" aria-expanded="false" aria-controls="ta-edit-<?= h($id) ?>"><i class="bi bi-pencil"></i> Modifier</button>
          <a class="btn btn-outline-secondary btn-sm" href="?page=<?= h($page_name) ?>&amp;action=test&amp;taid=<?= h($id) . h(csrf_query()) ?>" data-confirm="Valider le test ? Ceci enverra un tweet public sur votre compte..."><i class="bi bi-send"></i> Tester</a>
          <a class="btn btn-outline-danger btn-sm" href="?page=<?= h($page_name) ?>&amp;action=delete&amp;taid=<?= h($id) . h(csrf_query()) ?>" data-confirm="Supprimer le compte Twitter #<?= h($id) ?> ?"><i class="bi bi-trash"></i> Supprimer</a>
        </td>
      </tr>
      <tr class="collapse" id="ta-edit-<?= h($id) ?>">
        <td colspan="<?= count($ta_columns) + 2 ?>">
          <form action="?page=<?= h($page_name) ?>" method="POST" autocomplete="off" class="p-2">
            <?= csrf_field() ?>
            <input type="hidden" name="ta_form" value="edit" />
            <input type="hidden" name="ta_id" value="<?= h($id) ?>" />
            <div class="row g-2">
<?php foreach ($ta_columns as $column => $label) { ?>
              <div class="col-12 col-md-6 col-xl-3">
                <label class="form-label small" for="edit_<?= h($id . '_' . $column) ?>"><?= h($label) ?></label>
                <input type="password" class="form-control form-control-sm font-monospace" id="edit_<?= h($id . '_' . $column) ?>" name="<?= h($column) ?>" value="" placeholder="<?= h($ta_mask($account[$column])) ?>" maxlength="255" autocomplete="new-password" spellcheck="false" />
              </div>
<?php } ?>
            </div>
            <div class="form-text mb-2">Laisser un champ vide pour conserver la valeur actuelle.</div>
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-save"></i> Enregistrer</button>
          </form>
        </td>
      </tr>
<?php } ?>
    </tbody>
  </table>
</div>
<?php } ?>
