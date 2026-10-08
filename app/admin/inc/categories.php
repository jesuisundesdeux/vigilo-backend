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

/*
 * Categories of the instance: the national ones (vigilo-conf) can be disabled for this
 * instance, and the instance can add its own (ids from 1000, never used by vigilo-conf).
 * The applications read the result with get_categories.php.
 */
define('VIGILO_CUSTOM_CATEGORY_FIRST_ID', 1000);

$messages = array();
$self_url = '?page=' . urlencode($page_name);

if (!function_exists('category_color')) {
    /* A CSS color name or #rrggbb, nothing else (used in a style attribute) */
    function category_color($value)
    {
        $value = strtolower(trim((string) $value));
        return preg_match('/^(#[0-9a-f]{6}|[a-z]{3,20})$/', $value) ? $value : '#9e9e9e';
    }
}

$national = array();
foreach (getNationalCategoriesList() as $category) {
    if (is_array($category) && isset($category['catid'])) {
        $national[intval($category['catid'])] = $category;
    }
}
$local = array();
$query = mysqli_query($db, "SELECT * FROM obs_categories ORDER BY cat_id");
while ($query && ($row = mysqli_fetch_assoc($query))) {
    $local[intval($row['cat_id'])] = $row;
}

/* Disable / enable a national category for this instance */
if (isset($_GET['action']) && in_array($_GET['action'], array('disable', 'enable'), true) && isset($_GET['catid'])) {
    $catid = intval($_GET['catid']);
    if (!isset($national[$catid])) {
        $messages[] = array('warning', 'Catégorie nationale <strong>#' . $catid . '</strong> introuvable.');
    } else {
        $disabled = $_GET['action'] == 'disable' ? 1 : 0;
        mysqli_query($db, "INSERT INTO obs_categories (cat_id, cat_custom, cat_disabled) VALUES (" . $catid . ", 0, " . $disabled . ")
                           ON DUPLICATE KEY UPDATE cat_disabled = " . $disabled);
        audit_log($disabled ? 'category_disable' : 'category_enable', 'category:' . $catid, array('name' => $national[$catid]['catname']));
        $messages[] = array('success', 'Catégorie <strong>' . h($national[$catid]['catname']) . '</strong> ' . ($disabled ? 'désactivée' : 'réactivée') . ' pour cette instance.');
        $local[$catid] = array('cat_id' => $catid, 'cat_custom' => 0, 'cat_disabled' => $disabled);
    }
}

/* Delete a category of the instance (only if no observation uses it) */
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['catid'])) {
    $catid = intval($_GET['catid']);
    $count = mysqli_query($db, "SELECT COUNT(*) FROM obs_list WHERE obs_categorie = " . $catid);
    $used  = $count ? intval(mysqli_fetch_array($count)[0]) : 0;
    if (!isset($local[$catid]) || !$local[$catid]['cat_custom']) {
        $messages[] = array('warning', 'Catégorie <strong>#' . $catid . '</strong> introuvable.');
    } elseif ($used > 0) {
        $messages[] = array('warning', 'La catégorie <strong>' . h($local[$catid]['cat_name']) . '</strong> est utilisée par ' . $used . ' observation(s) : la désactiver plutôt que la supprimer.');
    } elseif (mysqli_query($db, "DELETE FROM obs_categories WHERE cat_id = " . $catid . " AND cat_custom = 1")) {
        audit_log('category_delete', 'category:' . $catid, array('name' => $local[$catid]['cat_name']));
        $messages[] = array('success', 'Catégorie <strong>' . h($local[$catid]['cat_name']) . '</strong> supprimée.');
        unset($local[$catid]);
    }
}

/* Create / update a category of the instance */
if (isset($_POST['category_save'])) {
    $catid = isset($_POST['cat_id']) ? intval($_POST['cat_id']) : 0;
    $name  = trim(isset($_POST['cat_name']) ? (string) $_POST['cat_name'] : '');
    $name_en = trim(isset($_POST['cat_name_en']) ? (string) $_POST['cat_name_en'] : '');
    $color = category_color(isset($_POST['cat_color']) ? $_POST['cat_color'] : '');
    $resolvable = !empty($_POST['cat_resolvable']) ? 1 : 0;
    $disabled   = empty($_POST['cat_active']) ? 1 : 0;
    if ($name === '' || mb_strlen($name) > 100 || mb_strlen($name_en) > 100) {
        $messages[] = array('danger', 'Le nom est obligatoire (100 caractères au plus).');
    } else {
        $values = "'" . mysqli_real_escape_string($db, $name) . "', '" . mysqli_real_escape_string($db, $name_en) . "', '"
                . mysqli_real_escape_string($db, $color) . "', " . $resolvable . ", " . $disabled;
        if ($catid > 0) {
            if (!isset($local[$catid]) || !$local[$catid]['cat_custom']) {
                $messages[] = array('warning', 'Catégorie <strong>#' . $catid . '</strong> introuvable.');
            } elseif (mysqli_query($db, "UPDATE obs_categories SET cat_name = '" . mysqli_real_escape_string($db, $name) . "', cat_name_en = '"
                       . mysqli_real_escape_string($db, $name_en) . "', cat_color = '" . mysqli_real_escape_string($db, $color) . "', cat_resolvable = "
                       . $resolvable . ", cat_disabled = " . $disabled . " WHERE cat_id = " . $catid . " AND cat_custom = 1")) {
                audit_log('category_edit', 'category:' . $catid, array('name' => $name, 'active' => !$disabled));
                $messages[] = array('success', 'Catégorie <strong>' . h($name) . '</strong> enregistrée.');
            }
        } else {
            $next  = VIGILO_CUSTOM_CATEGORY_FIRST_ID;
            $query = mysqli_query($db, "SELECT MAX(cat_id) FROM obs_categories WHERE cat_custom = 1");
            $max   = $query ? intval(mysqli_fetch_array($query)[0]) : 0;
            $catid = max($next, $max + 1);
            if (mysqli_query($db, "INSERT INTO obs_categories (cat_id, cat_custom, cat_name, cat_name_en, cat_color, cat_resolvable, cat_disabled)
                                   VALUES (" . $catid . ", 1, " . $values . ")")) {
                audit_log('category_create', 'category:' . $catid, array('name' => $name));
                $messages[] = array('success', 'Catégorie <strong>' . h($name) . '</strong> ajoutée (n° ' . $catid . ').');
            } else {
                $messages[] = array('danger', 'Impossible d\'ajouter la catégorie.');
            }
        }
    }
    $local = array();
    $query = mysqli_query($db, "SELECT * FROM obs_categories ORDER BY cat_id");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $local[intval($row['cat_id'])] = $row;
    }
}

foreach ($messages as $message) {
    // Messages are built above from escaped / integer values only
    echo '<div class="alert alert-' . h($message[0]) . '" role="alert">' . $message[1] . '</div>';
}

$counts = array();
$query  = mysqli_query($db, "SELECT obs_categorie, COUNT(*) AS nb FROM obs_list GROUP BY obs_categorie");
while ($query && ($row = mysqli_fetch_assoc($query))) {
    $counts[intval($row['obs_categorie'])] = intval($row['nb']);
}
$custom = array_filter($local, function ($row) { return (bool) $row['cat_custom']; });
?>
<p class="text-body-secondary small">
  Les catégories nationales sont communes à toutes les instances (<a href="https://github.com/jesuisundesdeux/vigilo-conf/blob/main/main/categorielist.json" target="_blank" rel="noopener noreferrer">vigilo-conf</a>).
  Cette instance peut en désactiver (elles ne sont plus proposées dans l'application, les observations existantes gardent
  leur catégorie) et ajouter les siennes. L'application web lit la liste de l'instance (<code>get_categories.php</code>) ;
  les applications qui ne la lisent pas encore continuent d'utiliser la liste nationale.
</p>

<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold"><i class="bi bi-globe-europe-africa"></i> Catégories nationales</div>
  <?php if (empty($national)) { ?>
    <div class="card-body"><p class="text-body-secondary mb-0">Liste nationale indisponible (GitHub injoignable) : réessayer plus tard.</p></div>
  <?php } else { ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle table-admin mb-0">
      <thead><tr><th scope="col">N°</th><th scope="col">Nom</th><th scope="col">Résolvable</th><th scope="col">Observations</th><th scope="col">État</th><th scope="col"></th></tr></thead>
      <tbody>
      <?php foreach ($national as $catid => $category) {
          $national_off = !empty($category['catdisable']);
          $local_off    = isset($local[$catid]) && !$local[$catid]['cat_custom'] && $local[$catid]['cat_disabled']; ?>
        <tr<?= ($national_off || $local_off) ? ' class="text-body-secondary"' : '' ?>>
          <td><?= intval($catid) ?></td>
          <td><span class="d-inline-block rounded-circle me-2 align-middle" style="width: .8rem; height: .8rem; background: <?= h(category_color(isset($category['catcolor']) ? $category['catcolor'] : '')) ?>;"></span><?= h($category['catname']) ?></td>
          <td><?= !empty($category['catresolvable']) ? 'oui' : 'non' ?></td>
          <td><?= isset($counts[$catid]) ? intval($counts[$catid]) : 0 ?></td>
          <td>
            <?php if ($national_off) { ?><span class="badge text-bg-secondary">Désactivée nationalement</span>
            <?php } elseif ($local_off) { ?><span class="badge text-bg-warning">Désactivée sur l'instance</span>
            <?php } else { ?><span class="badge text-bg-success">Active</span><?php } ?>
          </td>
          <td class="text-end text-nowrap">
            <?php if (!$national_off && !$local_off) { ?>
              <a class="btn btn-sm btn-outline-warning" href="<?= h($self_url) ?>&amp;action=disable&amp;catid=<?= intval($catid) ?><?= h(csrf_query()) ?>">Désactiver</a>
            <?php } elseif ($local_off) { ?>
              <a class="btn btn-sm btn-outline-success" href="<?= h($self_url) ?>&amp;action=enable&amp;catid=<?= intval($catid) ?><?= h(csrf_query()) ?>">Réactiver</a>
            <?php } ?>
          </td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
  <?php } ?>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold"><i class="bi bi-tags"></i> Catégories de l'instance</div>
  <div class="table-responsive">
    <table class="table align-middle table-admin mb-0">
      <thead><tr><th scope="col">N°</th><th scope="col">Nom</th><th scope="col">Nom (anglais)</th><th scope="col">Couleur</th><th scope="col">Résolvable</th><th scope="col">Active</th><th scope="col">Obs.</th><th scope="col"></th></tr></thead>
      <tbody>
      <?php
      $rows = $custom;
      $rows[0] = array('cat_id' => 0, 'cat_name' => '', 'cat_name_en' => '', 'cat_color' => '#e67e22', 'cat_resolvable' => 1, 'cat_disabled' => 0);
      $forms = '';
      foreach ($rows as $catid => $row) {
          $form_id = 'category' . intval($catid) . 'form';
          $forms  .= '<form method="POST" action="' . h($self_url) . '" id="' . h($form_id) . '">' . csrf_field()
                   . '<input type="hidden" name="category_save" value="1" /><input type="hidden" name="cat_id" value="' . intval($catid) . '" /></form>';
          $color = category_color($row['cat_color']); ?>
        <tr<?= $catid == 0 ? ' class="table-light"' : '' ?>>
          <td><?= $catid ? intval($catid) : '<span class="text-body-secondary small">Nouvelle</span>' ?></td>
          <td><input class="form-control form-control-sm" name="cat_name" form="<?= h($form_id) ?>" maxlength="100" value="<?= h($row['cat_name']) ?>" aria-label="Nom" <?= $catid ? 'required' : 'placeholder="Nom de la catégorie"' ?>></td>
          <td><input class="form-control form-control-sm" name="cat_name_en" form="<?= h($form_id) ?>" maxlength="100" value="<?= h($row['cat_name_en']) ?>" aria-label="Nom en anglais"></td>
          <td><input class="form-control form-control-sm form-control-color" type="color" name="cat_color" form="<?= h($form_id) ?>" value="<?= h(preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : '#9e9e9e') ?>" aria-label="Couleur"></td>
          <td><input class="form-check-input" type="checkbox" name="cat_resolvable" value="1" form="<?= h($form_id) ?>"<?= $row['cat_resolvable'] ? ' checked' : '' ?> aria-label="Résolvable"></td>
          <td><input class="form-check-input" type="checkbox" name="cat_active" value="1" form="<?= h($form_id) ?>"<?= $row['cat_disabled'] ? '' : ' checked' ?> aria-label="Active"></td>
          <td><?= $catid ? (isset($counts[$catid]) ? intval($counts[$catid]) : 0) : '' ?></td>
          <td class="text-end text-nowrap">
            <button class="btn btn-sm <?= $catid ? 'btn-outline-primary' : 'btn-primary' ?>" type="submit" form="<?= h($form_id) ?>"><i class="bi <?= $catid ? 'bi-check-lg' : 'bi-plus-lg' ?>"></i> <?= $catid ? 'Enregistrer' : 'Ajouter' ?></button>
            <?php if ($catid) { ?>
              <a class="btn btn-sm btn-outline-danger" href="<?= h($self_url) ?>&amp;action=delete&amp;catid=<?= intval($catid) ?><?= h(csrf_query()) ?>" data-confirm="Supprimer la catégorie « <?= h($row['cat_name']) ?> » ?"><i class="bi bi-trash"></i></a>
            <?php } ?>
          </td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
  <div class="card-body small text-body-secondary">
    Les catégories de l'instance sont numérotées à partir de <?= VIGILO_CUSTOM_CATEGORY_FIRST_ID ?> (numéros jamais utilisés par la liste nationale).
    « Résolvable » : les citoyens peuvent déclarer l'observation résolue. Une catégorie utilisée par des observations ne peut
    pas être supprimée : la désactiver.
  </div>
</div>
<?= $forms ?>
