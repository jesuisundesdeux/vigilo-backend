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

// https://stackoverflow.com/questions/8995096/php-determine-visually-corrupted-images-yet-valid-downloaded-via-curl-with-gd
function isGoodImage($fn)
{
    list($w, $h) = getimagesize($fn);
    if ($w < 50 || $h < 50)
        return 0;
    $im   = imagecreatefromstring(file_get_contents($fn));
    $grey = 0;

    for ($i = 0; $i < 5; ++$i) {
        for ($j = 0; $j < 5; ++$j) {
            $x = $w - 5 + $i;
            $y = $h - 5 + $j;
            list($r, $g, $b) = array_values(imagecolorsforindex($im, imagecolorat($im, $x, $y)));
            if ($r == $g && $g == $b && $b == 128)
                ++$grey;
        }
    }
    return $grey < 12;
}

function hasAllowedType($filepath)
{
    $allowedTypes = array(
        IMAGETYPE_JPEG
    );
    $detectedType = exif_imagetype($filepath);

    return in_array($detectedType,$allowedTypes);
}

function saveImageOnDisk($method, $filepath, $error_prefix)
{
    if ($method == 'base64') {
        return saveImageOnDiskFromBase64($filepath, $error_prefix);
    }

    return saveImageOnDiskFromStdinOrInput($filepath, $error_prefix);
}

function saveImageOnDiskFromBase64($filepath, $error_prefix)
{
    if (isset($_POST['imagebin64'])) {
        $data = $_POST['imagebin64'];
    } else {
        // Also accept a JSON body {"imagebin64": "..."} (#267)
        $json = json_decode(file_get_contents('php://input'), true);
        $data = (is_array($json) && isset($json['imagebin64'])) ? $json['imagebin64'] : '';
    }
    // Data URLs ("data:image/jpeg;base64,...") are accepted
    $data          = preg_replace('/^data:[^,]*,/', '', (string) $data);
    $image_content = base64_decode(str_replace(array(
        '-',
        '_',
        ' ',
        '\n'
    ), array(
        '+',
        '/',
        '+',
        ' '
    ), $data));
    $fd_image      = fopen($filepath, "wb");
    $image_written = fwrite($fd_image, $image_content) !== false;
    fclose($fd_image);

    return $image_written;
}

function saveImageOnDiskFromStdinOrInput($filepath, $error_prefix)
{
    $data = file_get_contents("php://stdin");
    if (file_put_contents($filepath, $data)) {
        return true;
    }

    $data = file_get_contents('php://input');
    if (file_put_contents($filepath, $data)) {
        return true;
    }

    jsonError($error_prefix, "Error uploading image with input", "IMAGEUPLOADFAILED", 500);
    return false;
}

function pixalize($filepath)
{
    # Blocks of 1/25th of the photo: enough to hide plates and faces
    # (1/100th let them be read, especially on resized panels)
    $RATIO_PIXELATED = 25;

    $photo = imagecreatefromjpeg($filepath); // issue photo

    $photo_w = imagesx($photo);
    $photo_h = imagesy($photo);
    if ($photo_w > $photo_h) {
        $pixelate_size = $photo_w / $RATIO_PIXELATED;
    } else {
        $pixelate_size = $photo_h / $RATIO_PIXELATED;
    }
    # Then apply pixelating + gaussian filters
    imagefilter($photo, IMG_FILTER_PIXELATE, max(1, intval($pixelate_size)), True);

    # Gaussian blur reduces pixel effect on small images
    imagefilter($photo, IMG_FILTER_GAUSSIAN_BLUR);

    return $photo;
}

function resizeImage($image, $maxWitdh, $maxHeight) {
    $origWidth = imagesx($image);
    $origHeight = imagesy($image);

    $ratio_orig = $origWidth/$origHeight;
    if ($origHeight < $maxHeight && $origWidth < $maxWitdh) {
        $width = $origWidth;
        $height = $origHeight;
    } elseif ($maxWitdh/$maxHeight > $ratio_orig) {
        $width = $maxHeight * $ratio_orig;
        $height = $maxHeight;
    } else {
        $width = $maxWitdh;
        $height = $maxWitdh/$ratio_orig;
    }

    // Resize
    $image_p = imagecreatetruecolor(round($width), round($height));

    if ($image_p !== false) {
        imagecopyresampled($image_p, $image, 0, 0, 0, 0, round($width), round($height), $origWidth, $origHeight);
    }

    return $image_p;
}