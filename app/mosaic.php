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

$cwd = dirname(__FILE__);

require_once("{$cwd}/includes/common.php");
require_once("{$cwd}/includes/functions.php");
require_once("{$cwd}/includes/security.php");
// Only defines the GetIssues class when included
require_once("{$cwd}/get_issues.php");

// get_issues.php sends the API headers: the mosaic is a page, not an API response
header_remove('Access-Control-Allow-Origin');
header_remove('BACKEND_VERSION');
header('Content-Type: text/html; charset=utf-8');

$cat   = (isset($_GET['c']) && $_GET['c'] !== '') ? (string) $_GET['c'] : 'all';
$token = (isset($_GET['t']) && $_GET['t'] !== '') ? (string) $_GET['t'] : 'all';

$obslink       = 'image';
$instance_name = '';
if (isset($_GET['scope']) && $_GET['scope'] !== '') {
    if ($instance_name = getInstanceNameFromFirebase((string) $_GET['scope'])) {
        $obslink = 'web';
    }
}

// Same list as get_issues.php without parameters (no HTTP call to ourselves anymore)
$export  = new GetIssues();
$content = $export->getIssues();

$similar = array();
if ($token != 'all') {
    $similar = sameas($token, array(
        'distance' => 300,
        'fdistance' => 1,
        'fcategorie' => 1,
        'faddress' => 1
    ));
}

$language = isset($config['VIGILO_LANGUAGE']) ? substr($config['VIGILO_LANGUAGE'], 0, 2) : 'fr';
$title    = isset($config['VIGILO_NAME']) ? $config['VIGILO_NAME'] : 'Vigilo';
$base     = $config['HTTP_PROTOCOL'] . '://' . $config['URLBASE'];
?>
<!DOCTYPE html>
<html lang="<?= h($language) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <link href="style/mosaic.css" type="text/css" rel="stylesheet">
  <link rel="icon" type="image/png" href="style/favicon.png">
  <script>
  if (document.location.search.match(/type=embed/gi)) {
    window.parent.postMessage("resize", "*");
  }
  </script>
</head>
<body>
  <div class="grid">
<?php
foreach ($content as $value) {
    if ($cat != 'all' && $value['categorie'] != $cat) {
        /* Wrong category - Do not display */
        continue;
    }
    if ($token != 'all' && !in_array($value['token'], $similar)) {
        /* Wrong token - Do not display */
        continue;
    }

    if ($obslink == 'web') {
        $obsurl = 'https://app.vigilo.city/?token=' . urlencode($value['token']) . '&instance=' . urlencode($instance_name);
    } else {
        $obsurl = 'generate_panel.php?token=' . urlencode($value['token']);
    }

    $src = $base . '/generate_panel.php?token=' . urlencode($value['token']) . '&s=400';
    echo '    <a class="grid-item" target="_blank" rel="noopener" href="' . h($obsurl) . '"><img loading="lazy" src="' . h($src) . '" alt="' . h($value['token']) . '"></a>' . "\n";
}
?>
  </div>
</body>
</html>
