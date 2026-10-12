<?php
/*
Copyright (C) 2026 Velocité Montpellier

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
 * First configuration of an instance: steps shown on the dashboard (admins) until the
 * required ones are done. Included by inc/dashboard.php (defines functions only).
 */

function admin_setup_count($db, $sql)
{
    $query = mysqli_query($db, $sql);
    return $query ? intval(mysqli_fetch_array($query)[0]) : 0;
}

/*
 * Steps: key => array(title, page of the admin, done (true / false / null = unknown),
 * required, help). $remote: also check the listing of the scopes in vigilo-conf
 * (cached list, fetched at most once a day).
 */
function admin_setup_checklist($db, $config, $remote = false)
{
    $scopes = array();
    $query  = mysqli_query($db, "SELECT scope_name FROM obs_scopes ORDER BY scope_id");
    while ($query && ($row = mysqli_fetch_array($query))) {
        $scopes[] = (string) $row['scope_name'];
    }
    $urlbase = isset($config['URLBASE']) ? trim((string) $config['URLBASE']) : '';
    $name    = isset($config['VIGILO_NAME']) ? trim((string) $config['VIGILO_NAME']) : '';

    $steps = array();
    $steps['settings'] = array('Nom et adresse de l\'instance', 'settings', $urlbase !== '' && $name !== '', true,
        'Nom affiché, URL de base et protocole utilisés pour les liens publics (photos, webhooks, application).');
    $steps['scopes'] = array('Territoire (scope)', 'scopes', count($scopes) > 0, true,
        'Zone couverte par l\'instance : nom (XX_nom), emprise de la carte, contact. Le scope est demandé par l\'application à chaque observation.');
    $steps['cities'] = array('Villes', 'cities', admin_setup_count($db, "SELECT COUNT(*) FROM obs_cities") > 0, true,
        'Communes du territoire (import depuis geo.api.gouv.fr) : elles rattachent les observations à une ville, pour les filtres, les statistiques et les comptes citystaff.');

    // No scope yet: to do; otherwise unknown until the list of vigilo-conf is read
    $listed = $scopes ? null : false;
    $custom = function_exists('vigilo_conf_is_custom') && vigilo_conf_is_custom();
    if ($remote && $scopes && function_exists('getInstanceNameFromFirebase')) {
        $list = getCachedRemoteJson($config['VIGILO_CONF_URL'] . '/citylist.json', 'citylist', $custom ? 300 : 86400);
        if ($list) {
            $listed = false;
            foreach ($scopes as $scope) {
                $listed = $listed || getInstanceNameFromFirebase($scope) !== false;
            }
        }
    }
    $steps['vigiloconf'] = array('Référencement dans l\'application', 'scopes', $listed, true, $custom
        // VIGILO_CONF_URL (demo): the list is built by the host from the scopes of the instance
        ? 'L\'application web de cette instance lit la liste des instances de ' . $config['VIGILO_CONF_URL'] . '/citylist.json '
          . '(VIGILO_CONF_URL) : le scope y apparaît quelques minutes après sa création.'
        : 'Pour apparaître dans l\'application web (app.vigilo.city) et sur vigilo.city, l\'instance doit être ajoutée à la liste '
          . 'citylist.json du dépôt vigilo-conf (pull request ou demande à l\'équipe Vigilo), avec le même scope.');
    $steps['accounts'] = array('Modérateurs', 'accounts', admin_setup_count($db, "SELECT COUNT(*) FROM obs_roles WHERE role_name = 'moderator'") > 0, false,
        'Comptes moderator : modération depuis l\'application web avec leur clé (générée par l\'instance ou fournie par la personne).');
    return $steps;
}
