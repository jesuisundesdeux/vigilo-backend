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

/*
 * Webhooks: on the events of the workflow (webhook_events()), every enabled webhook of
 * the admin (page "Webhooks") subscribed to the event is called:
 * - observation.created: new observation, once its photo is received (add_image.php);
 * - observation.approved / observation.disapproved: moderation (admin or approve.php);
 * - resolution.created: resolution declared in the application or created in the admin;
 * - resolution.status_changed: status of a resolution changed in the admin.
 * A webhook stores its events as a comma-separated list (webhook_event).
 *
 * The URL, the headers and the body are templates: {{variable}} is replaced by the
 * value of the observation (see webhook_variables()), escaped for where it is used:
 * URL-encoded in the URL and in a "form" body, JSON-escaped in a "json" body (write
 * "{{comment}}" with the quotes), as is in a "text" body, without line breaks in
 * the headers.
 *
 * The calls are made in parallel with a short timeout (VIGILO_WEBHOOK_TIMEOUT,
 * 5 seconds) and logged in obs_webhook_deliveries; a failing endpoint never
 * prevents the publication.
 */

define('VIGILO_WEBHOOK_EVENT_CREATED', 'observation.created');
define('VIGILO_WEBHOOK_EVENT_APPROVED', 'observation.approved');
define('VIGILO_WEBHOOK_EVENT_DISAPPROVED', 'observation.disapproved');
define('VIGILO_WEBHOOK_EVENT_RESOLUTION_CREATED', 'resolution.created');
define('VIGILO_WEBHOOK_EVENT_RESOLUTION_STATUS', 'resolution.status_changed');
define('VIGILO_WEBHOOK_LOG_KEEP', 500);

/* Events a webhook can subscribe to, with their description (admin page) */
function webhook_events()
{
    return array(
        VIGILO_WEBHOOK_EVENT_CREATED           => 'Nouvelle observation (avant modération)',
        VIGILO_WEBHOOK_EVENT_APPROVED          => 'Observation publiée (approuvée)',
        VIGILO_WEBHOOK_EVENT_DISAPPROVED       => 'Observation refusée (désapprouvée)',
        VIGILO_WEBHOOK_EVENT_RESOLUTION_CREATED => 'Nouvelle résolution',
        VIGILO_WEBHOOK_EVENT_RESOLUTION_STATUS => 'Changement d\'état d\'une résolution',
    );
}

/* Events of a webhook (column webhook_event, comma-separated) */
function webhook_hook_events($hook)
{
    $events = array();
    foreach (explode(',', isset($hook['webhook_event']) ? (string) $hook['webhook_event'] : '') as $event) {
        if (isset(webhook_events()[trim($event)])) {
            $events[] = trim($event);
        }
    }
    return $events;
}

/* Name of a resolution status */
function webhook_resolution_status_name($status)
{
    $names = array(0 => 'Nouvelle', 1 => 'Résolue', 2 => 'Prise en compte', 3 => 'En cours de résolution', 4 => 'Indiquée résolue (à valider)');
    return isset($names[intval($status)]) ? $names[intval($status)] : '';
}

/* Variables available in the templates, with their description (admin page) */
function webhook_variables()
{
    return array(
        'event'           => 'Événement (observation.created, observation.approved, observation.disapproved, resolution.created, resolution.status_changed)',
        'token'           => 'Identifiant de l\'observation',
        'observation_url' => 'Lien vers l\'observation dans l\'application web',
        'photo_url'       => 'Lien vers la photo (pixelisée tant que l\'observation n\'est pas approuvée)',
        'comment'         => 'Commentaire',
        'explanation'     => 'Explication',
        'categorie'       => 'Numéro de la catégorie',
        'categorie_name'  => 'Nom de la catégorie',
        'categorie_code'  => 'Code de la catégorie dans l\'outil appelé (correspondance du webhook)',
        'address'         => 'Adresse',
        'cityname'        => 'Ville',
        'scope'           => 'Scope',
        'lat'             => 'Latitude',
        'lon'             => 'Longitude',
        'time'            => 'Date de l\'observation (timestamp Unix)',
        'date'            => 'Date de l\'observation (ISO 8601)',
        'status'          => 'Statut (0 nouvelle, 1 résolue, 2 prise en compte, 3 en cours, 4 indiquée résolue)',
        'approved'        => 'Modération (0 à qualifier, 1 approuvée, 2 refusée)',
        'resolution_token'           => 'Résolution : identifiant (événements resolution.*)',
        'resolution_status'          => 'Résolution : état (1 résolue, 2 prise en compte, 3 en cours, 4 indiquée résolue)',
        'resolution_status_name'     => 'Résolution : nom de l\'état',
        'resolution_previous_status' => 'Résolution : état précédent (resolution.status_changed)',
        'resolution_comment'         => 'Résolution : commentaire',
        'resolution_date'            => 'Résolution : date (ISO 8601)',
        'resolution_photo_url'       => 'Résolution : lien vers la photo (vide sans photo)',
        'resolution_observations'    => 'Résolution : identifiants des observations liées, séparés par des virgules ; les variables de l\'observation sont celles de la première',
        'instance_name'   => 'Nom de l\'instance',
        'instance_url'    => 'Adresse de l\'instance',
    );
}

/* Values of the variables for an observation, null if it does not exist */
function webhook_observation_values($db, $token, $event = VIGILO_WEBHOOK_EVENT_APPROVED)
{
    global $config;
    $query = mysqli_query($db, "SELECT obs_list.*, obs_cities.city_name FROM obs_list LEFT JOIN obs_cities ON obs_list.obs_city = obs_cities.city_id
                                WHERE obs_token = '" . mysqli_real_escape_string($db, $token) . "' LIMIT 1");
    $obs   = $query ? mysqli_fetch_assoc($query) : null;
    if (!$obs) {
        return null;
    }
    // Same city as get_issues.php: the city found from the address, else the name typed in the app
    $cityname = (!empty($obs['obs_city']) && $obs['city_name'] !== null) ? $obs['city_name'] : $obs['obs_cityname'];
    $proto        = (isset($config['HTTP_PROTOCOL']) && $config['HTTP_PROTOCOL'] !== '') ? $config['HTTP_PROTOCOL'] : 'https';
    $instance_url = $proto . '://' . (isset($config['URLBASE']) ? $config['URLBASE'] : '');
    $time         = intval($obs['obs_time']);
    return array(
        'event'           => $event,
        'token'           => (string) $obs['obs_token'],
        'observation_url' => null, // computed only when used (remote list of the instances)
        // Public for every state (pixelated until approved): get_photo.php refuses the photos not approved yet
        'photo_url'       => $instance_url . '/generate_panel.php?token=' . rawurlencode($obs['obs_token']),
        'comment'         => (string) $obs['obs_comment'],
        'explanation'     => (string) $obs['obs_explanation'],
        'categorie'       => (string) $obs['obs_categorie'],
        'categorie_name'  => null, // computed only when used (remote list of the categories)
        'categorie_code'  => '',   // set for each webhook (webhook_category_code())
        'address'         => (string) $obs['obs_address_string'],
        'cityname'        => (string) $cityname,
        'scope'           => (string) $obs['obs_scope'],
        'lat'             => (string) $obs['obs_coordinates_lat'],
        'lon'             => (string) $obs['obs_coordinates_lon'],
        'time'            => (string) $time,
        'date'            => date('c', $time),
        'status'          => (string) $obs['obs_status'],
        'approved'        => (string) $obs['obs_approved'],
        'resolution_token'           => '',
        'resolution_status'          => '',
        'resolution_status_name'     => '',
        'resolution_previous_status' => '',
        'resolution_comment'         => '',
        'resolution_date'            => '',
        'resolution_photo_url'       => '',
        'resolution_observations'    => '',
        'instance_name'   => isset($config['VIGILO_NAME']) ? (string) $config['VIGILO_NAME'] : '',
        'instance_url'    => $instance_url,
    );
}

/*
 * Values of the variables for a resolution: those of its first observation, plus the
 * resolution_* ones. Null if the resolution does not exist.
 */
function webhook_resolution_values($db, $resolutionid, $event, $previous_status = null)
{
    global $config;
    $query = mysqli_query($db, "SELECT * FROM obs_resolutions WHERE resolution_id = " . intval($resolutionid) . " LIMIT 1");
    $res   = $query ? mysqli_fetch_assoc($query) : null;
    if (!$res) {
        return null;
    }
    $tokens = array();
    $query  = mysqli_query($db, "SELECT obs_list.obs_token FROM obs_resolutions_tokens INNER JOIN obs_list ON obs_list.obs_id = obs_resolutions_tokens.restok_observationid
                                 WHERE obs_resolutions_tokens.restok_resolutionid = " . intval($resolutionid) . " ORDER BY obs_list.obs_id");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $tokens[] = (string) $row['obs_token'];
    }
    $values = $tokens ? webhook_observation_values($db, $tokens[0], $event) : null;
    if (!$values) {
        $proto        = (isset($config['HTTP_PROTOCOL']) && $config['HTTP_PROTOCOL'] !== '') ? $config['HTTP_PROTOCOL'] : 'https';
        $values = array_fill_keys(array_keys(webhook_variables()), '');
        $values['event']         = $event;
        $values['instance_name'] = isset($config['VIGILO_NAME']) ? (string) $config['VIGILO_NAME'] : '';
        $values['instance_url']  = $proto . '://' . (isset($config['URLBASE']) ? $config['URLBASE'] : '');
    }
    $time = intval($res['resolution_time']);
    $values['resolution_token']           = (string) $res['resolution_token'];
    $values['resolution_status']          = (string) $res['resolution_status'];
    $values['resolution_status_name']     = webhook_resolution_status_name($res['resolution_status']);
    $values['resolution_previous_status'] = $previous_status === null ? '' : (string) intval($previous_status);
    $values['resolution_comment']         = (string) $res['resolution_comment'];
    $values['resolution_date']            = $time > 0 ? date('c', $time) : '';
    $values['resolution_photo_url']       = !empty($res['resolution_withphoto']) ? $values['instance_url'] . '/get_photo.php?type=resolution&token=' . rawurlencode($res['resolution_token']) : '';
    $values['resolution_observations']    = implode(',', $tokens);
    return $values;
}

/* Value of a variable, computing the remote ones on first use */
function webhook_value(&$values, $name)
{
    if (!array_key_exists($name, $values)) {
        return '';
    }
    if ($values[$name] === null) {
        $values[$name] = '';
        if ($name == 'categorie_name') {
            foreach ((array) getCategoriesList() as $categorie) {
                if (isset($categorie['catid'], $categorie['catname']) && (string) $categorie['catid'] === $values['categorie']) {
                    $values[$name] = (string) $categorie['catname'];
                }
            }
        } elseif ($name == 'observation_url') {
            $instance = getInstanceNameFromFirebase($values['scope']);
            $values[$name] = 'https://app.vigilo.city/?' . ($instance ? 'instance=' . rawurlencode($instance) . '&' : '') . 'token=' . rawurlencode($values['token']);
        }
    }
    return (string) $values[$name];
}

/* Escapes a value for where it is used in the template */
function webhook_escape($value, $mode)
{
    switch ($mode) {
        case 'json':
            return substr(json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
        case 'form':
        case 'url':
            return rawurlencode($value);
        case 'header':
            return str_replace(array("\r", "\n"), ' ', $value);
        default:
            return $value;
    }
}

/* Replaces the {{variables}} of a template (unknown ones are replaced by nothing) */
function webhook_render($template, &$values, $mode)
{
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use (&$values, $mode) {
        return webhook_escape(webhook_value($values, $m[1]), $mode);
    }, (string) $template);
}

/* Correspondence of the categories of a webhook: Vigilo category id => code of the called tool */
function webhook_category_map($hook)
{
    $map = isset($hook['webhook_category_map']) ? json_decode((string) $hook['webhook_category_map'], true) : null;
    return is_array($map) ? $map : array();
}

/* Code of the category of the observation for a webhook, null if it has none */
function webhook_category_code($hook, $values)
{
    $map = webhook_category_map($hook);
    return (isset($values['categorie']) && isset($map[$values['categorie']]) && (string) $map[$values['categorie']] !== '') ? (string) $map[$values['categorie']] : null;
}

/* The HTTP request of a webhook for these values: url, method, headers, body */
function webhook_build_request($hook, &$values)
{
    $code = webhook_category_code($hook, $values);
    $values['categorie_code'] = $code === null ? '' : $code;
    $format  = in_array($hook['webhook_format'], array('json', 'form', 'text'), true) ? $hook['webhook_format'] : 'json';
    $method  = in_array($hook['webhook_method'], array('POST', 'PUT', 'PATCH', 'GET'), true) ? $hook['webhook_method'] : 'POST';
    $headers = array();
    $has_ct  = false;
    foreach (preg_split('/\r\n|\r|\n/', (string) $hook['webhook_headers']) as $line) {
        if (strpos($line, ':') === false || trim($line) === '') {
            continue;
        }
        list($name, $value) = explode(':', $line, 2);
        $name = trim($name);
        if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
            continue;
        }
        $has_ct    = $has_ct || strcasecmp($name, 'Content-Type') == 0;
        $headers[] = $name . ': ' . trim(webhook_render($value, $values, 'header'));
    }
    $body = $method == 'GET' ? '' : webhook_render($hook['webhook_body'], $values, $format);
    if (!$has_ct && $body !== '') {
        $types     = array('json' => 'application/json', 'form' => 'application/x-www-form-urlencoded', 'text' => 'text/plain; charset=utf-8');
        $headers[] = 'Content-Type: ' . $types[$format];
    }
    $headers[] = 'User-Agent: vigilo-backend/' . BACKEND_VERSION;
    return array(
        'url'     => webhook_render($hook['webhook_url'], $values, 'url'),
        'method'  => $method,
        'headers' => $headers,
        'body'    => $body,
    );
}

/* Enabled webhooks of an event */
function webhooks_for_event($db, $event)
{
    $hooks = array();
    $query = mysqli_query($db, "SELECT * FROM obs_webhooks WHERE webhook_enabled = 1 AND FIND_IN_SET('" . mysqli_real_escape_string($db, $event) . "', REPLACE(webhook_event, ' ', '')) > 0 ORDER BY webhook_id");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $hooks[] = $row;
    }
    return $hooks;
}

/*
 * Calls the webhooks with the values of an observation, in parallel, and logs the
 * results. Returns the deliveries: webhook id => array(http_code, error, duration_ms, response).
 */
function webhooks_deliver($db, $hooks, $values)
{
    $results = array();
    if (empty($hooks) || !function_exists('curl_multi_init')) {
        return $results;
    }
    $timeout = intval(getenv('VIGILO_WEBHOOK_TIMEOUT'));
    $timeout = $timeout > 0 ? $timeout : 5;
    $multi   = curl_multi_init();
    $handles = array();
    foreach ($hooks as $hook) {
        // "Only the mapped categories": the other observations are not sent to this webhook
        if (!empty($hook['webhook_category_only']) && webhook_category_code($hook, $values) === null) {
            continue;
        }
        $request = webhook_build_request($hook, $values);
        $id      = intval($hook['webhook_id']);
        if (!preg_match('#^https?://#i', $request['url'])) {
            $results[$id] = array(0, 'URL invalide (http:// ou https:// attendu)', 0, '');
            continue;
        }
        $ch = curl_init($request['url']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request['method']);
        if ($request['method'] != 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request['headers']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(3, $timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_multi_add_handle($multi, $ch);
        $handles[$id] = $ch;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 0.2);
        }
    } while ($running && $status == CURLM_OK);

    foreach ($handles as $id => $ch) {
        $code     = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $error    = curl_error($ch);
        $response = (string) curl_multi_getcontent($ch);
        if ($error === '' && ($code < 200 || $code >= 300)) {
            $error = 'HTTP ' . $code;
        }
        $results[$id] = array($code, $error, intval(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000), $response);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);

    // Logged token: the resolution for its events, else the observation
    $log_token = (strpos($values['event'], 'resolution.') === 0 && !empty($values['resolution_token'])) ? $values['resolution_token'] : $values['token'];
    foreach ($results as $id => $result) {
        list($code, $error, $duration, $response) = $result;
        mysqli_query($db, "INSERT INTO obs_webhook_deliveries (delivery_webhookid, delivery_time, delivery_event, delivery_token, delivery_http_code, delivery_error, delivery_duration_ms, delivery_response)
                           VALUES (" . intval($id) . ", " . time() . ", '" . mysqli_real_escape_string($db, $values['event']) . "', '" . mysqli_real_escape_string($db, $log_token) . "', "
                           . intval($code) . ", '" . mysqli_real_escape_string($db, substr($error, 0, 255)) . "', " . intval($duration) . ", '" . mysqli_real_escape_string($db, mb_substr($response, 0, 500)) . "')");
        if ($error !== '') {
            error_log('[WARNING] WEBHOOK: webhook #' . $id . ' for ' . $log_token . ' (' . $values['event'] . ') - ' . $error);
        }
    }
    // Only the last deliveries are kept
    $query = mysqli_query($db, "SELECT delivery_id FROM obs_webhook_deliveries ORDER BY delivery_id DESC LIMIT " . VIGILO_WEBHOOK_LOG_KEEP . ",1");
    if ($query && ($row = mysqli_fetch_assoc($query))) {
        mysqli_query($db, "DELETE FROM obs_webhook_deliveries WHERE delivery_id <= " . intval($row['delivery_id']));
    }
    return $results;
}

/* Approval state of an observation before a change (null if it does not exist) */
function webhook_approval_state($db, $token)
{
    $query = mysqli_query($db, "SELECT obs_approved FROM obs_list WHERE obs_token = '" . mysqli_real_escape_string($db, $token) . "' LIMIT 1");
    $row   = $query ? mysqli_fetch_assoc($query) : null;
    return $row ? intval($row['obs_approved']) : null;
}

/* Calls the webhooks of an event for an observation */
function webhooks_for_observation($db, $event, $token)
{
    $hooks = webhooks_for_event($db, $event);
    if (empty($hooks)) {
        return array();
    }
    $values = webhook_observation_values($db, $token, $event);
    return $values ? webhooks_deliver($db, $hooks, $values) : array();
}

/* To call after a change of approval: observation.approved when it becomes published, observation.disapproved when refused */
function webhooks_on_approval($db, $token, $before, $after)
{
    if ($before === null || intval($before) === intval($after)) {
        return array();
    }
    if (intval($after) === 1) {
        return webhooks_for_observation($db, VIGILO_WEBHOOK_EVENT_APPROVED, $token);
    }
    if (intval($after) === 2) {
        return webhooks_for_observation($db, VIGILO_WEBHOOK_EVENT_DISAPPROVED, $token);
    }
    return array();
}

/* Calls the webhooks of an event for a resolution */
function webhooks_for_resolution($db, $event, $resolutionid, $previous_status = null)
{
    $hooks = webhooks_for_event($db, $event);
    if (empty($hooks)) {
        return array();
    }
    $values = webhook_resolution_values($db, $resolutionid, $event, $previous_status);
    return $values ? webhooks_deliver($db, $hooks, $values) : array();
}

/* To call after a change of status of a resolution */
function webhooks_on_resolution_status($db, $resolutionid, $before, $after)
{
    if ($before === null || intval($before) === intval($after)) {
        return array();
    }
    return webhooks_for_resolution($db, VIGILO_WEBHOOK_EVENT_RESOLUTION_STATUS, $resolutionid, $before);
}

/* Status of a resolution (null if it does not exist) */
function webhook_resolution_state($db, $resolutionid)
{
    $query = mysqli_query($db, "SELECT resolution_status FROM obs_resolutions WHERE resolution_id = " . intval($resolutionid) . " LIMIT 1");
    $row   = $query ? mysqli_fetch_assoc($query) : null;
    return $row ? intval($row['resolution_status']) : null;
}

/*
 * Sends the response to the client now and keeps running (for the webhooks), so
 * that the application does not wait for them.
 */
function vigilo_send_response_and_continue($body)
{
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        echo $body;
        fastcgi_finish_request();
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Connection: close');
    header('Content-Length: ' . strlen($body));
    echo $body;
    flush();
}
