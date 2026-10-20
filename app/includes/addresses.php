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
 * Addresses of the observations: cleanup and city detection, at the creation of an observation
 * (create_issue.php) and in the admin ("Qualité des adresses" of the page Observations).
 * The address is stored without its city (obs_address_string), the city in obs_city (referenced
 * city) or obs_cityname (free text); get_issues.php puts them together again.
 * Pure functions (no database), see tests/problems/unit.php.
 */
require_once(dirname(__FILE__) . '/functions.php');

/* Spaces, apostrophes and commas of a text tidied ("12 ,  rue  d’Alger ,," -> "12, rue d'Alger") */
function address_tidy($text)
{
    $text = str_replace(array("\u{2019}", "\u{2018}", '`'), "'", (string) $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $parts = array();
    foreach (explode(',', $text) as $part) {
        $part = trim($part, " \t.;");
        if ($part !== '') {
            $parts[] = $part;
        }
    }
    return implode(', ', $parts);
}

/* Key to compare city names: without case, accents, punctuation; "St"/"Ste" = "Saint"/"Sainte" */
function address_city_key($name)
{
    $name = preg_replace_callback('/(^|[\s\-])(ste|st)\.?(?=[\s\-]|$)/iu', function ($m) {
        return $m[1] . (strtolower($m[2]) == 'ste' ? 'sainte' : 'saint');
    }, trim((string) $name));
    return vigilo_street_key($name);
}

/*
 * Address typed or sent by an application -> array(street, city, postcode). The city is the last
 * part after a comma; postcodes (5 digits) and the country are taken out, a lone house number is
 * joined to its street ("12, rue X, 34000 Montpellier, France" -> "12 rue X", "Montpellier", "34000").
 */
function address_parse($address)
{
    $postcode = '';
    $parts    = array();
    foreach (explode(',', address_tidy($address)) as $part) {
        $part = trim($part);
        if (preg_match('/^(france|fr)$/i', $part)) {
            continue;
        }
        if (preg_match('/(?<!\d)(\d{5})(?!\d)/', $part, $m)) {
            $postcode = $m[1];
            $part = trim(preg_replace('/(?<!\d)\d{5}(?!\d)/', '', $part));
        }
        if ($part === '') {
            continue;
        }
        // A lone house number belongs to the next part ("12, rue X")
        if ($parts && preg_match('/^\d+\s*(bis|ter|quater|[a-d])?$/i', end($parts))) {
            $parts[count($parts) - 1] .= ' ' . $part;
            continue;
        }
        $parts[] = $part;
    }
    if (count($parts) < 2) {
        return array('street' => $parts ? $parts[0] : '', 'city' => '', 'postcode' => $postcode);
    }
    $city = array_pop($parts);
    return array('street' => implode(', ', $parts), 'city' => $city, 'postcode' => $postcode);
}

/*
 * Referenced city of a name (or of a postcode when there is no name, or no city of that name):
 * $cities = array(city_id => array('name' => ..., 'postcode' => ...)). Returns the id, or 0.
 */
function address_match_city($name, $postcode, $cities)
{
    $key = address_city_key($name);
    if ($key !== '') {
        foreach ($cities as $id => $city) {
            if (address_city_key($city['name']) === $key) {
                return intval($id);
            }
        }
    }
    if ($postcode !== '' && $key === '') {
        $found = array();
        foreach ($cities as $id => $city) {
            if (in_array($postcode, preg_split('/\D+/', (string) $city['postcode'], -1, PREG_SPLIT_NO_EMPTY), true)) {
                $found[] = intval($id);
            }
        }
        if (count($found) == 1) {
            return $found[0];
        }
    }
    return 0;
}

/*
 * Correction of the address and of the city of an observation (row of obs_list: obs_address_string,
 * obs_city, obs_cityname). Returns array(address, city, cityname, reasons), reasons among
 * format, number, street_type, city, city_known, city_extracted, city_duplicate; same values when nothing changes.
 * The address has no house number: only the street (vigilo_street_name()).
 */
function address_fix($row, $cities)
{
    $address  = (string) $row['obs_address_string'];
    $city     = intval($row['obs_city']);
    $cityname = trim((string) $row['obs_cityname']);
    $reasons  = array();
    $parsed   = address_parse($address);
    if ($city > 0 && !isset($cities[$city])) {
        $city = 0;
    }

    if ($city == 0 && $cityname !== '') {
        // Free city name of a referenced city
        $match = address_match_city($cityname, $parsed['postcode'], $cities);
        if ($match) {
            $city = $match;
            $cityname = '';
            $reasons[] = 'city_known';
        }
    }
    if ($city == 0 && $cityname === '') {
        // City taken from the address
        $match = address_match_city($parsed['city'], $parsed['postcode'], $cities);
        if ($match) {
            $city = $match;
            $reasons[] = 'city';
            if (address_city_key($parsed['city']) === address_city_key($cities[$match]['name'])) {
                $parsed['city'] = '';
            }
        } elseif ($parsed['city'] !== '') {
            $cityname = $parsed['city'];
            $parsed['city'] = '';
            $reasons[] = 'city_extracted';
        }
    }
    // The city is kept out of the address (get_issues.php adds it)
    $reference = $city > 0 ? $cities[$city]['name'] : $cityname;
    if ($parsed['city'] !== '' && $reference !== '' && address_city_key($parsed['city']) === address_city_key($reference)) {
        $parsed['city'] = '';
        $reasons[] = 'city_duplicate';
    }
    // No house number before the street
    $parts = $parsed['street'] === '' ? array() : explode(', ', $parsed['street']);
    if ($parts && vigilo_street_name($parts[0]) !== $parts[0] && vigilo_street_name($parts[0]) !== '') {
        $parts[0] = vigilo_street_name($parts[0]);
        $parsed['street'] = implode(', ', $parts);
        $reasons[] = 'number';
    }
    // Type of the street in full, with a capital ("bd X" -> "Boulevard X")
    if ($parts && vigilo_street_type_normalize($parts[0]) !== $parts[0]) {
        $parts[0] = vigilo_street_type_normalize($parts[0]);
        $parsed['street'] = implode(', ', $parts);
        $reasons[] = 'street_type';
    }
    $new = $parsed['city'] !== '' ? $parsed['street'] . ', ' . $parsed['city'] : $parsed['street'];
    if ($new === '') {
        $new = trim($address);
    }
    if ($new !== $address && !array_intersect($reasons, array('city', 'city_extracted', 'city_duplicate', 'number', 'street_type'))) {
        $reasons[] = 'format';
    }
    return array('address' => $new, 'city' => $city, 'cityname' => $cityname, 'reasons' => $reasons);
}

/*
 * Same street written differently in the same city ("avenue de lodeve", "Avenue de Lodève"):
 * the most frequent spelling (on equal counts, the one with accents and capitals).
 * $rows: obs_id => array(address without house number, city key). Returns obs_id => new address.
 */
function address_spelling_fixes($rows)
{
    $groups = array();
    foreach ($rows as $id => $row) {
        $address = $row['address'];
        if (strpos($address, ',') !== false) {
            continue;
        }
        $name = vigilo_street_name($address);
        $key  = vigilo_street_key($name);
        if ($key === '' || $name === '') {
            continue;
        }
        $group = $row['city'] . '|' . $key;
        $groups[$group]['names'][$name] = (isset($groups[$group]['names'][$name]) ? $groups[$group]['names'][$name] : 0) + 1;
        $groups[$group]['rows'][$id] = $name;
    }
    $fixes = array();
    foreach ($groups as $group) {
        if (count($group['names']) < 2) {
            continue;
        }
        $names = array_keys($group['names']);
        usort($names, function ($a, $b) use ($group) {
            if ($group['names'][$a] != $group['names'][$b]) {
                return $group['names'][$b] - $group['names'][$a];
            }
            return address_spelling_score($b) - address_spelling_score($a);
        });
        $best = $names[0];
        foreach ($group['rows'] as $id => $name) {
            if ($rows[$id]['address'] !== $best) {
                $fixes[$id] = $best;
            }
        }
    }
    return $fixes;
}

/* Preference between two spellings: accents, then a capital at the start of words */
function address_spelling_score($name)
{
    return preg_match_all('/[^\x00-\x7F]/u', $name) * 2 + preg_match_all('/(?:^|\s)\p{Lu}/u', $name);
}

/*
 * Proposed corrections of the observations ($rows: obs_id => row with obs_token,
 * obs_address_string, obs_city, obs_cityname): obs_id => array(token, before, after, reasons),
 * before / after = array(address, city, cityname). Only the observations that change.
 */
function address_cleanup_proposals($rows, $cities)
{
    $fixed = array();
    foreach ($rows as $id => $row) {
        $fixed[$id] = address_fix($row, $cities);
    }
    $spelling = array();
    foreach ($fixed as $id => $fix) {
        $spelling[$id] = array('address' => $fix['address'],
                               'city' => $fix['city'] > 0 ? 'id' . $fix['city'] : address_city_key($fix['cityname']));
    }
    foreach (address_spelling_fixes($spelling) as $id => $address) {
        $fixed[$id]['address']   = $address;
        $fixed[$id]['reasons'][] = 'spelling';
    }
    $proposals = array();
    foreach ($fixed as $id => $fix) {
        $row = $rows[$id];
        if ($fix['address'] === (string) $row['obs_address_string'] && $fix['city'] == intval($row['obs_city'])
            && $fix['cityname'] === trim((string) $row['obs_cityname'])) {
            continue;
        }
        $proposals[$id] = array('token' => (string) $row['obs_token'],
                                'before' => array('address' => (string) $row['obs_address_string'], 'city' => intval($row['obs_city']), 'cityname' => (string) $row['obs_cityname']),
                                'after' => array('address' => $fix['address'], 'city' => $fix['city'], 'cityname' => $fix['cityname']),
                                'reasons' => array_values(array_unique($fix['reasons'])));
    }
    return $proposals;
}

/*
 * What a format correction changes, when it cannot be seen once displayed (HTML collapses
 * spaces): list of labels (extra spaces, space at the start or end, non-breaking space, apostrophe).
 */
function address_format_details($before, $after)
{
    $before  = (string) $before;
    $details = array();
    if (preg_match('/\x{00A0}|\x{202F}|\t/u', $before)) {
        $details[] = 'espace insécable ou tabulation remplacé';
    }
    if (preg_match('/ {2,}/', $before)) {
        $details[] = 'espaces en double retirés';
    }
    if ($before !== '' && (trim($before) !== $before)) {
        $details[] = 'espace au début ou à la fin retiré';
    }
    if (preg_match('/\s,|,(?=\S)|,\s*,|,\s*$/u', $before)) {
        $details[] = 'virgules et espaces autour remis en forme';
    }
    if (preg_match('/[\x{2019}\x{2018}`]/u', $before)) {
        $details[] = 'apostrophe remplacée';
    }
    return $details;
}

/* Address in HTML with its invisible characters shown (extra, leading or trailing spaces, non-breaking spaces) */
function address_visible_html($address)
{
    $html = h((string) $address);
    $mark = function ($title, $text) {
        return '<span class="addr-invisible" title="' . $title . '">' . $text . '</span>';
    };
    $html = preg_replace('/\x{00A0}|\x{202F}/u', $mark('espace insécable', '⍽'), $html);
    $html = str_replace("\t", $mark('tabulation', '⇥'), $html);
    $html = preg_replace_callback('/^ +| +$| {2,}/', function ($m) use ($mark) {
        return $mark('espace en trop', str_repeat('␣', strlen($m[0])));
    }, $html);
    return $html;
}

/* Labels of the reasons of a correction */
function address_reason_labels()
{
    return array('city' => 'Ville reconnue dans l\'adresse', 'city_known' => 'Ville non référencée reconnue',
                 'city_extracted' => 'Ville extraite (non référencée)', 'city_duplicate' => 'Ville en double retirée',
                 'number' => 'Numéro retiré', 'street_type' => 'Type de voie uniformisé', 'format' => 'Mise en forme', 'spelling' => 'Orthographe de la rue');
}
