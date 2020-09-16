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

function GeneratePanel($photo, $map, $comment, $street_name, $token, $categorie_string, $date, $statusobs)
{
    # Responsive Photo process
    $cwd = dirname(__FILE__);
    
    $photo_w     = imagesx($photo);
    $photo_h     = imagesy($photo);
    $photo_ratio = $photo_w / $photo_h;
    
    # Portrait format
    if ($photo_ratio < 1) {
        $backgroung_image = "${cwd}/panel_components/portrait/background.jpg";
        $content_image    = "${cwd}/panel_components/portrait/content.png";
        $logo_image       = "${cwd}/panel_components/portrait/logo.png";
        $resolved_image   = "${cwd}/panel_components/portrait/resolved.png";
        
        # Load background image
        $image        = imagecreatefromjpeg($backgroung_image);
        $background_w = imagesx($image);
        $background_h = imagesy($image);
        
        $photo_max_w = 470;
        $photo_max_h = 800;
        
        $photo_new_size_h = $photo_max_h;
        $photo_new_size_w = $photo_new_size_h * $photo_ratio;
        
        while ($photo_new_size_w > $photo_max_w or $photo_new_size_h > $photo_max_h) {
            $photo_new_size_w--;
            $photo_new_size_h = $photo_new_size_w / $photo_ratio;
        }
        
        $photo_position_x     = 380;
        $photo_min_y          = $background_h - (103 + $photo_new_size_h);
        $map_x                = -10;
        $map_y                = 543;
        $comment_y_frombottom = 50;
        $comment_span_x       = 0;
        $categorie_y          = 240;
        $address_y            = 330;
        $date_y               = 515;
        $copyright_x          = 2;
        
        $resolved_x = 520;
        $resolved_y = -15;
        $resolved_w = 300;
        $resolved_h = 150;
        
    }
    # Landscape format
    else {
        $backgroung_image = "${cwd}/panel_components/landscape/background.jpg";
        $content_image    = "${cwd}/panel_components/landscape/content.png";
        $logo_image       = "${cwd}/panel_components/landscape/logo.png";
        $resolved_image   = "${cwd}/panel_components/landscape/resolved.png";
        
        # Load background image
        $image        = imagecreatefromjpeg($backgroung_image);
        $background_w = imagesx($image);
        $background_h = imagesy($image);
        
        $photo_max_w = 900;
        $photo_max_h = 600;
        
        $photo_new_size_h = $photo_max_h;
        $photo_new_size_w = $photo_new_size_h * $photo_ratio;
        while ($photo_new_size_w > $photo_max_w or $photo_new_size_h > $photo_max_h) {
            $photo_new_size_h--;
            $photo_new_size_w = $photo_new_size_h * $photo_ratio;
        }
        
        $photo_position_x     = $background_w - $photo_new_size_w;
        $photo_min_y          = 103;
        $map_x                = -5;
        $map_y                = 438;
        $comment_y_frombottom = 40;
        $comment_span_x       = 423;
        $categorie_y          = 250;
        $address_y            = 330;
        $date_y               = 415;
        $copyright_x          = 280;
        
        $resolved_x = 960;
        $resolved_y = -15;
        $resolved_w = 300;
        $resolved_h = 150;
    }
    
    ## INIT IMAGE ##
    $font_regular = "${cwd}/fonts/texgyreheros-regular.otf";
    $font_italic  = "${cwd}/fonts/texgyreheros-italic.otf";
    $font_bold    = "${cwd}/fonts/texgyreheros-bold.otf";
    
    $fontcolor     = imagecolorallocate($image, 54, 66, 86);
    $fontcolorgrey = imagecolorallocate($image, 219, 219, 219);
    $white         = imagecolorallocate($image, 255, 255, 255);
    $black         = imagecolorallocate($image, 0, 0, 0);
    $green         = imagecolorallocate($image, 35, 122, 68);
    
    ## ADD PHOTO
    ## Photo
    $photo_position_y = $photo_min_y;
    
    imagecopyresized($image, $photo, $photo_position_x, $photo_position_y, 0, 0, $photo_new_size_w, $photo_new_size_h, $photo_w, $photo_h);
    
    $mask        = imagecreatetruecolor(360, 360);
    $transparent = imagecolorallocate($mask, 255, 0, 0);
    $alpha       = imagecolorallocate($mask, 0, 0, 0);
    
    $map_circle = imagecreatetruecolor(360, 360);
    imagecopymerge($map_circle, $map, 0, 0, 0, 0, imagesx($map), imagesy($map), 100);
    
    imagealphablending($map_circle, true);
    
    imagecolortransparent($mask, $transparent);
    
    imagefilledellipse($mask, 360 / 2, 360 / 2, 360, 360, $transparent);
    imagecopymerge($map_circle, $mask, 0, 0, 0, 0, 360, 360, 100);
    imagecolortransparent($map_circle, $alpha);
    imagefill($map_circle, 0, 0, $alpha);
    
    imagecopymerge($image, $map_circle, $map_x, $map_y, 0, 0, 360, 360, 100);
    
    ## ADD MAP COPYRIGHTS ##
    imagettftext($image, 7, 0, $copyright_x, $background_h - 13, $white, $font_regular, "©2020 MAPQUEST ©OPENSTREETMAP ©MAPBOX");
    
    ## ADD CONTENT BLOCK ##
    $content_block = imagecreatefrompng($content_image);
    imagecopy($image, $content_block, 0, 0, 0, 0, imagesx($content_block), imagesy($content_block));
    
    ## ADD LOGO ##
    $logo = imagecreatefrompng($logo_image); // logo #JeSuisUnDesDeux
    imagecopy($image, $logo, 0, 0, 0, 0, imagesx($logo), imagesy($logo));
    
    ## ADD COMMENT
    $comment_color     = imagecolorallocate($image, 255, 219, 80);
    $comment_font_size = 25;
    
    if (!empty($comment)) {
        $comment     = '"' . trim($comment) . '"';
        $comment_box = imagettfbbox($comment_font_size, 0, $font_italic, $comment);
        $comment_x   = (($background_w - $comment_span_x) - ($comment_box[2] - $comment_box[0])) / 2;
        imagettftext($image, $comment_font_size, 0, $comment_span_x + $comment_x, $background_h - $comment_y_frombottom, $comment_color, $font_italic, $comment);
    }
    
    ## ADD TOKEN
    $token_font_size = 25;
    imagettftext($image, $token_font_size, 0, 200, 160, $black, $font_regular, $token);
    
    ## ADD CATEGORIE
    $categorie_font_size         = 26;
    $categorie_max_char_per_line = 18;
    
    $categorie_string_formatted = wordwrap($categorie_string, $categorie_max_char_per_line, "===");
    $categories_nblines         = substr_count($categorie_string_formatted, "===");
    
    if ($categories_nblines > 1) {
        $categorie_max_char_per_line = 25;
        $categorie_string_formatted  = wordwrap($categorie_string, $categorie_max_char_per_line, "===");
    }
    
    $categories_lines = explode('===', $categorie_string_formatted);
    foreach ($categories_lines as $categories_line) {
        imagettftext($image, $categorie_font_size, 0, 29, $categorie_y, $black, $font_bold, $categories_line);
        $categorie_y += 35;
    }
    
    ## ADD ADDRESS
    $address_font_size         = 18;
    $address_max_char_per_line = 25;
    $street_name               = wordwrap($street_name, $address_max_char_per_line, "===");
    
    $address_lines = explode('===', $street_name);
    
    foreach ($address_lines as $address_line) {
        imagettftext($image, $address_font_size, 0, 29, $address_y, $black, $font_bold, $address_line);
        $address_y += 28;
    }
    
    ## ADD DATE
    $date_font_size = 15;
    imagettftext($image, $date_font_size, 0, 29, $date_y, $black, $font_regular, $date);
    
    ## ADD RESOLVED
    if ($statusobs == 1) {
        $resolved = imagecreatefrompng($resolved_image);
        imagecopyresized($image, $resolved, $resolved_x, $resolved_y, 0, 0, $resolved_w, $resolved_h, imagesx($resolved), imagesy($resolved));
    }
    
    return $image;
}
