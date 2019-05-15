<?php
/*
Copyright (C) 2019 Velocité Montpellier

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

function convertToBoolean($value) {
    return $value ? 'true' : 'false';
}

function showRequirement($title, $require, $current, $passed)
{
    $status = convertToBoolean($passed);
    
    echo "
    <tr>
    <td headers='f1'>${title}</td>
    <td headers='f2'>${require}</td>
    <td headers='f3'>${current}</td>
    <td headers='f4'>${status}</td>
    </tr>";
    
}

function openPage()
{
    header('Content-Type: text/html; charset=UTF-8');
    echo '
    <?xml version="1.0" encoding="UTF-8"?>
    <!DOCTYPE html 
         PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
        "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
    <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
      <head>
        <title>Vigilo installation</title>
      </head>
      <body>

      <table>
      <tbody>
      <tr>
         <th id="f1">Description</th>
         <th id="f2">Valeur requise</th>
         <th id="f3">Valeur en cours</th>
         <th id="f4">Test Passé ?</th>
      </tr>';
}

function closePage()
{
    echo '   </tbody>
    </table>      
 </body>
        </html>';
}


$process_username = posix_getpwuid(posix_geteuid())['name'];
$process_userid = posix_getpwuid(posix_geteuid())['uid'];
$www_root = dirname(__FILE__);
$is_writable = is_writable($www_root);
$is_urlopen = (boolean)ini_get('allow_url_fopen');

openPage();
showRequirement('Version PHP','>= 7.1',PHP_VERSION,PHP_VERSION>=7.1);
showRequirement('Current username','',"${process_username}(${process_userid})",true);
showRequirement('www root folder','',$www_root,true);
showRequirement('is writable','true',convertToBoolean($is_writable),true==$is_writable);
showRequirement('allow_url_fopen','true',convertToBoolean($is_urlopen),true==$is_urlopen);

closePage();