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

$messages = array();
$self_url = '?page=' . urlencode($page_name);
$formats  = array('json' => 'JSON', 'form' => 'Formulaire (x-www-form-urlencoded)', 'text' => 'Texte brut');
$methods  = array('POST', 'PUT', 'PATCH', 'GET');

$default_body = "{\n  \"event\": \"{{event}}\",\n  \"token\": \"{{token}}\",\n  \"url\": \"{{observation_url}}\",\n  \"photo\": \"{{photo_url}}\",\n"
              . "  \"categorie\": \"{{categorie_name}}\",\n  \"comment\": \"{{comment}}\",\n  \"address\": \"{{address}}\",\n"
              . "  \"city\": \"{{cityname}}\",\n  \"lat\": {{lat}},\n  \"lon\": {{lon}},\n  \"date\": \"{{date}}\"\n}";

$empty_hook = array('webhook_id' => 0, 'webhook_name' => '', 'webhook_enabled' => 1, 'webhook_method' => 'POST',
                    'webhook_url' => '', 'webhook_format' => 'json', 'webhook_headers' => '', 'webhook_body' => $default_body);

function webhook_admin_load($db, $id)
{
    $query = mysqli_query($db, "SELECT * FROM obs_webhooks WHERE webhook_id = " . intval($id) . " LIMIT 1");
    return $query ? mysqli_fetch_assoc($query) : null;
}

/* Values used to test a webhook: the last published observation, or an example */
function webhook_admin_test_values($db)
{
    $query = mysqli_query($db, "SELECT obs_token FROM obs_list WHERE obs_approved = 1 AND obs_complete = 1 ORDER BY obs_time DESC LIMIT 1");
    $row   = $query ? mysqli_fetch_assoc($query) : null;
    $values = $row ? webhook_observation_values($db, $row['obs_token']) : null;
    if (!$values) {
        $values = array('event' => VIGILO_WEBHOOK_EVENT_APPROVED, 'token' => 'EXEMPLE1', 'observation_url' => 'https://app.vigilo.city/?token=EXEMPLE1',
                        'photo_url' => '', 'comment' => 'Voiture "garée" sur la piste', 'explanation' => '', 'categorie' => '2',
                        'categorie_name' => 'Stationnement', 'address' => '1 rue de l\'Exemple', 'cityname' => 'Exempleville', 'scope' => '',
                        'lat' => '43.6', 'lon' => '3.88', 'time' => (string) time(), 'date' => date('c'), 'status' => '0',
                        'instance_name' => '', 'instance_url' => '');
    }
    return $values;
}

$edit = null;

/* Delete */
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['webhookid'])) {
    $hook = webhook_admin_load($db, $_GET['webhookid']);
    if ($hook && mysqli_query($db, "DELETE FROM obs_webhooks WHERE webhook_id = " . intval($hook['webhook_id']))) {
        mysqli_query($db, "DELETE FROM obs_webhook_deliveries WHERE delivery_webhookid = " . intval($hook['webhook_id']));
        audit_log('webhook_delete', 'webhook:' . intval($hook['webhook_id']), array('name' => $hook['webhook_name']));
        $messages[] = array('success', 'Webhook <strong>' . h($hook['webhook_name']) . '</strong> supprimé.');
    }
}

/* Create / update */
if (isset($_POST['webhook_save']) || isset($_POST['webhook_test'])) {
    $edit = array(
        'webhook_id'      => isset($_POST['webhook_id']) ? intval($_POST['webhook_id']) : 0,
        'webhook_name'    => trim(isset($_POST['webhook_name']) ? (string) $_POST['webhook_name'] : ''),
        'webhook_enabled' => !empty($_POST['webhook_enabled']) ? 1 : 0,
        'webhook_method'  => (isset($_POST['webhook_method']) && in_array($_POST['webhook_method'], $methods, true)) ? $_POST['webhook_method'] : 'POST',
        'webhook_url'     => trim(isset($_POST['webhook_url']) ? (string) $_POST['webhook_url'] : ''),
        'webhook_format'  => (isset($_POST['webhook_format']) && isset($formats[$_POST['webhook_format']])) ? $_POST['webhook_format'] : 'json',
        'webhook_headers' => isset($_POST['webhook_headers']) ? str_replace("\r\n", "\n", (string) $_POST['webhook_headers']) : '',
        'webhook_body'    => isset($_POST['webhook_body']) ? str_replace("\r\n", "\n", (string) $_POST['webhook_body']) : '',
    );
    $errors = array();
    if ($edit['webhook_name'] === '' || strlen($edit['webhook_name']) > 100) {
        $errors[] = 'Le nom est obligatoire (100 caractères au plus).';
    }
    if (!preg_match('#^https?://[^\s"\'<>]+$#i', $edit['webhook_url']) || strlen($edit['webhook_url']) > 1000) {
        $errors[] = 'L\'URL doit commencer par http:// ou https://.';
    }
    if (strlen($edit['webhook_headers']) > 5000 || strlen($edit['webhook_body']) > 20000) {
        $errors[] = 'En-têtes ou corps trop longs.';
    }
    if ($edit['webhook_format'] == 'json' && $edit['webhook_method'] != 'GET' && trim($edit['webhook_body']) !== '') {
        $sample = webhook_admin_test_values($db);
        json_decode(webhook_render($edit['webhook_body'], $sample, 'json'));
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'Le corps ne donne pas un JSON valide une fois les variables remplacées (mettre les variables de texte entre guillemets : "{{comment}}").';
        }
    }

    if ($errors) {
        $messages[] = array('danger', 'Webhook non enregistré :<ul class="mb-0"><li>' . implode('</li><li>', array_map('h', $errors)) . '</li></ul>');
    } else {
        $fields = array();
        foreach (array('webhook_name', 'webhook_method', 'webhook_url', 'webhook_format', 'webhook_headers', 'webhook_body') as $field) {
            $fields[$field] = "'" . mysqli_real_escape_string($db, $edit[$field]) . "'";
        }
        $fields['webhook_enabled'] = intval($edit['webhook_enabled']);
        if ($edit['webhook_id'] > 0 && webhook_admin_load($db, $edit['webhook_id'])) {
            $sets = array();
            foreach ($fields as $field => $value) {
                $sets[] = $field . ' = ' . $value;
            }
            $ok = mysqli_query($db, "UPDATE obs_webhooks SET " . implode(', ', $sets) . " WHERE webhook_id = " . intval($edit['webhook_id']));
            audit_log('webhook_edit', 'webhook:' . intval($edit['webhook_id']), array('name' => $edit['webhook_name'], 'url' => $edit['webhook_url'], 'enabled' => $edit['webhook_enabled']));
        } else {
            $ok = mysqli_query($db, "INSERT INTO obs_webhooks (" . implode(', ', array_keys($fields)) . ", webhook_event) VALUES (" . implode(', ', $fields) . ", '" . VIGILO_WEBHOOK_EVENT_APPROVED . "')");
            $edit['webhook_id'] = intval(mysqli_insert_id($db));
            audit_log('webhook_create', 'webhook:' . $edit['webhook_id'], array('name' => $edit['webhook_name'], 'url' => $edit['webhook_url']));
        }
        if ($ok) {
            $messages[] = array('success', 'Webhook <strong>' . h($edit['webhook_name']) . '</strong> enregistré.');
            if (isset($_POST['webhook_test'])) {
                $values  = webhook_admin_test_values($db);
                $request = webhook_build_request($edit, $values);
                $hook    = webhook_admin_load($db, $edit['webhook_id']);
                $result  = webhooks_deliver($db, array($hook), $values);
                $result  = isset($result[$edit['webhook_id']]) ? $result[$edit['webhook_id']] : array(0, 'non envoyé', 0, '');
                $level   = $result[1] === '' ? 'success' : 'danger';
                $messages[] = array($level, 'Test avec l\'observation <strong>' . h($values['token']) . '</strong> : '
                    . ($result[1] === '' ? 'HTTP ' . intval($result[0]) : h($result[1])) . ' en ' . intval($result[2]) . ' ms.'
                    . '<details class="mt-2"><summary>Requête envoyée</summary><pre class="small mb-0 mt-2">'
                    . h($request['method'] . ' ' . $request['url'] . "\n" . implode("\n", $request['headers']) . "\n\n" . $request['body'])
                    . '</pre></details>'
                    . ($result[3] !== '' ? '<details class="mt-2"><summary>Réponse</summary><pre class="small mb-0 mt-2">' . h(mb_substr($result[3], 0, 500)) . '</pre></details>' : ''));
            }
            $edit = null;
        } else {
            $messages[] = array('danger', 'Impossible d\'enregistrer le webhook.');
        }
    }
}

/* Form to show */
if ($edit === null && isset($_GET['edit'])) {
    $edit = ($_GET['edit'] === 'new') ? $empty_hook : webhook_admin_load($db, $_GET['edit']);
}

foreach ($messages as $message) {
    // Messages are built above from escaped / integer values only
    echo '<div class="alert alert-' . h($message[0]) . '" role="alert">' . $message[1] . '</div>';
}

$hooks = array();
$query = mysqli_query($db, "SELECT w.*, (SELECT CONCAT(delivery_time, '|', delivery_http_code, '|', delivery_error) FROM obs_webhook_deliveries d
                                         WHERE d.delivery_webhookid = w.webhook_id ORDER BY delivery_id DESC LIMIT 1) AS last_delivery
                            FROM obs_webhooks w ORDER BY webhook_id");
while ($query && ($row = mysqli_fetch_assoc($query))) {
    $hooks[] = $row;
}
?>
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="h5 mb-0"><i class="bi bi-broadcast"></i> Webhooks</h2>
    <a class="btn btn-sm btn-primary" href="<?= h($self_url) ?>&amp;edit=new"><i class="bi bi-plus-lg"></i> Ajouter un webhook</a>
  </div>
  <div class="card-body pb-0">
    <p class="small text-body-secondary">
      À chaque publication d'une observation (validation par un modérateur, depuis l'admin ou l'application), chaque webhook
      actif appelle son adresse avec les en-têtes et le corps définis ci-dessous, où les <code>{{variables}}</code> sont
      remplacées par les champs de l'observation. Les appels sont faits en parallèle (5 secondes au plus) ; un échec
      n'empêche pas la publication et apparaît dans le journal ci-dessous.
      <a href="https://github.com/jesuisundesdeux/vigilo-backend/blob/master/doc/WEBHOOKS.md" target="_blank" rel="noopener noreferrer">Exemples : Mastodon, Slack, Bluesky, ticketing de collectivité <i class="bi bi-box-arrow-up-right"></i></a>
    </p>
  </div>
  <?php if (empty($hooks)) { ?>
    <div class="card-body pt-0"><p class="text-body-secondary mb-0">Aucun webhook.</p></div>
  <?php } else { ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle table-admin mb-0">
      <thead>
        <tr><th scope="col">Nom</th><th scope="col">Appel</th><th scope="col">État</th><th scope="col">Dernier envoi</th><th scope="col"></th></tr>
      </thead>
      <tbody>
      <?php foreach ($hooks as $hook) {
        $last = $hook['last_delivery'] ? explode('|', $hook['last_delivery'], 3) : null; ?>
        <tr>
          <td class="fw-semibold"><?= h($hook['webhook_name']) ?></td>
          <td class="text-break small"><span class="badge text-bg-secondary"><?= h($hook['webhook_method']) ?></span> <?= h($hook['webhook_url']) ?></td>
          <td><?= $hook['webhook_enabled'] ? '<span class="badge text-bg-success">Actif</span>' : '<span class="badge text-bg-secondary">Désactivé</span>' ?></td>
          <td class="small">
            <?php if ($last) { ?>
              <?= h(date('d/m/Y H:i', intval($last[0]))) ?>
              <?= $last[2] === '' ? '<span class="badge text-bg-success">HTTP ' . h($last[1]) . '</span>' : '<span class="badge text-bg-danger">' . h($last[2]) . '</span>' ?>
            <?php } else { ?>—<?php } ?>
          </td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= h($self_url) ?>&amp;edit=<?= intval($hook['webhook_id']) ?>"><i class="bi bi-pencil"></i> Modifier</a>
            <a class="btn btn-sm btn-outline-danger" href="<?= h($self_url) ?>&amp;action=delete&amp;webhookid=<?= intval($hook['webhook_id']) ?><?= h(csrf_query()) ?>" data-confirm="Supprimer le webhook « <?= h($hook['webhook_name']) ?> » ?"><i class="bi bi-trash"></i></a>
          </td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
  <?php } ?>
</div>

<?php if ($edit) { ?>
<div class="row g-4 mb-4">
  <div class="col-12 col-xl-7">
    <form class="card shadow-sm" method="POST" action="<?= h($self_url) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="webhook_id" value="<?= intval($edit['webhook_id']) ?>">
      <div class="card-header fw-semibold"><?= $edit['webhook_id'] ? 'Modifier le webhook' : 'Nouveau webhook' ?></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label" for="webhook_name">Nom</label>
            <input class="form-control" id="webhook_name" name="webhook_name" maxlength="100" required value="<?= h($edit['webhook_name']) ?>" placeholder="Carte de l'association">
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" role="switch" id="webhook_enabled" name="webhook_enabled" value="1"<?= $edit['webhook_enabled'] ? ' checked' : '' ?>>
              <label class="form-check-label" for="webhook_enabled">Actif</label>
            </div>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="webhook_method">Méthode</label>
            <select class="form-select" id="webhook_method" name="webhook_method">
              <?php foreach ($methods as $method) { ?><option<?= $edit['webhook_method'] == $method ? ' selected' : '' ?>><?= h($method) ?></option><?php } ?>
            </select>
          </div>
          <div class="col-md-9">
            <label class="form-label" for="webhook_url">URL</label>
            <input class="form-control font-monospace" id="webhook_url" name="webhook_url" type="text" required value="<?= h($edit['webhook_url']) ?>" placeholder="https://exemple.org/api/observations?token={{token}}">
          </div>
          <div class="col-12">
            <label class="form-label" for="webhook_headers">En-têtes <span class="text-body-secondary small">(un par ligne : <code>Nom: valeur</code>)</span></label>
            <textarea class="form-control font-monospace small" id="webhook_headers" name="webhook_headers" rows="3" placeholder="Authorization: Bearer mon-jeton&#10;X-Vigilo-Token: {{token}}"><?= h($edit['webhook_headers']) ?></textarea>
          </div>
          <div class="col-md-5">
            <label class="form-label" for="webhook_format">Format du corps</label>
            <select class="form-select" id="webhook_format" name="webhook_format">
              <?php foreach ($formats as $key => $label) { ?><option value="<?= h($key) ?>"<?= $edit['webhook_format'] == $key ? ' selected' : '' ?>><?= h($label) ?></option><?php } ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label" for="webhook_body">Corps</label>
            <textarea class="form-control font-monospace small" id="webhook_body" name="webhook_body" rows="12"><?= h($edit['webhook_body']) ?></textarea>
            <div class="form-text">
              JSON : les valeurs sont échappées, mettre les variables de texte entre guillemets (<code>"{{comment}}"</code>).
              Formulaire : <code>token={{token}}&amp;comment={{comment}}</code>, valeurs encodées. Ignoré pour GET.
            </div>
          </div>
        </div>
      </div>
      <div class="card-footer d-flex flex-wrap gap-2">
        <button class="btn btn-primary" type="submit" name="webhook_save" value="1"><i class="bi bi-save"></i> Enregistrer</button>
        <button class="btn btn-outline-primary" type="submit" name="webhook_test" value="1"><i class="bi bi-send"></i> Enregistrer et tester</button>
        <a class="btn btn-outline-secondary" href="<?= h($self_url) ?>">Annuler</a>
      </div>
    </form>
  </div>
  <div class="col-12 col-xl-5">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-braces"></i> Variables</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 small">
          <tbody>
          <?php foreach (webhook_variables() as $name => $description) { ?>
            <tr><td class="font-monospace text-nowrap">{{<?= h($name) ?>}}</td><td><?= h($description) ?></td></tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
      <div class="card-body small text-body-secondary">
        Utilisables dans l'URL, les en-têtes et le corps. « Enregistrer et tester » envoie la requête avec la dernière
        observation publiée.
      </div>
    </div>
  </div>
</div>
<?php } ?>

<?php
$query = mysqli_query($db, "SELECT d.*, w.webhook_name FROM obs_webhook_deliveries d LEFT JOIN obs_webhooks w ON w.webhook_id = d.delivery_webhookid
                            ORDER BY d.delivery_id DESC LIMIT 30");
$deliveries = array();
while ($query && ($row = mysqli_fetch_assoc($query))) {
    $deliveries[] = $row;
}
if ($deliveries) { ?>
<div class="card shadow-sm">
  <div class="card-header fw-semibold"><i class="bi bi-journal-text"></i> Derniers envois</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0 small">
      <thead><tr><th scope="col">Date</th><th scope="col">Webhook</th><th scope="col">Observation</th><th scope="col">Résultat</th><th scope="col">Durée</th><th scope="col">Réponse</th></tr></thead>
      <tbody>
      <?php foreach ($deliveries as $delivery) { ?>
        <tr>
          <td class="text-nowrap"><?= h(date('d/m/Y H:i:s', intval($delivery['delivery_time']))) ?></td>
          <td><?= h($delivery['webhook_name'] !== null ? $delivery['webhook_name'] : '#' . $delivery['delivery_webhookid']) ?></td>
          <td class="font-monospace"><?= h($delivery['delivery_token']) ?></td>
          <td><?= $delivery['delivery_error'] === '' ? '<span class="badge text-bg-success">HTTP ' . h($delivery['delivery_http_code']) . '</span>' : '<span class="badge text-bg-danger">' . h($delivery['delivery_error']) . '</span>' ?></td>
          <td class="text-nowrap"><?= intval($delivery['delivery_duration_ms']) ?> ms</td>
          <td class="text-break font-monospace"><?= h(mb_substr($delivery['delivery_response'], 0, 120)) ?></td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
</div>
<?php } ?>
