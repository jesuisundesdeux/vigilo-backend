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

use PHPUnit\Framework\TestCase;

set_include_path(implode(PATH_SEPARATOR, array(
    realpath(__DIR__ . '/../..'),
    get_include_path()
)));

require_once('app/includes/functions.php');

$TOKEN1="ABCDEF01";
$TOKEN2="ABCDEF02";

#TODO: tweet
#TODO: getrole
#TODO: generategroups


class FunctionsTest extends TestCase
{
    public static function setUpBeforeClass(): void {
        global $TOKEN1,$TOKEN2;

        # Delete all png caches files
        $root = realpath(__DIR__) . "/../../app/caches";
        foreach(glob("$root/*.png") as $file) {
            unlink($file);
        }
        # Init token files
        file_put_contents("$root/${TOKEN1}_big.png", "empty");
        file_put_contents("$root/${TOKEN1}_small.png", "empty");
        file_put_contents("$root/${TOKEN2}_big.png", "empty");
        file_put_contents("$root/${TOKEN2}_small.png", "empty");
        
    
        # Delete all jpg map files
        $root = realpath(__DIR__) . "/../../app/maps";
        foreach(glob("$root/*.jpg") as $file) {
            unlink($file);
        }
        file_put_contents("$root/${TOKEN1}.jpg", "empty");
        file_put_contents("$root/${TOKEN1}_zoom.jpg", "empty");
        file_put_contents("$root/${TOKEN2}.jpg", "empty");
        file_put_contents("$root/${TOKEN2}_zoom.jpg", "empty");

    }

    public static function tearDownAfterClass(): void {
        # Delete all png caches files
        $root = realpath(__DIR__) . "/../../app/caches";
        foreach(glob("$root/*.png") as $file) {
            unlink($file);
        }

        # Delete all jpg map files
        $root = realpath(__DIR__) . "/../../app/maps";
        foreach(glob("$root/*.jpg") as $file) {
            unlink($file);
        }
    }


    public function testDistanceFunction() {
        # in M
        $this->assertEquals(distance(8.86417880,2.34250440,43.6008177,3.8873392,'m'),3869775.0321862376);

        # In Km
        $this->assertEquals(distance(8.86417880,2.34250440,43.6008177,3.8873392),3869.7750321862377);
    }

    public function testDeleteTokenCache() {
        
        global $TOKEN1,$TOKEN2;
        $root = realpath(__DIR__) . "/../../app/caches";

        # Delete first token
        delete_token_cache("${TOKEN1}");
        $files = scandir($root);
        $this->assertFalse(in_array("${TOKEN1}_big.png",$files));
        $this->assertTrue(in_array("${TOKEN2}_big.png",$files));
        $this->assertTrue(in_array('index.html',$files));

        # Delete second token
        delete_token_cache("${TOKEN2}");
        $files = scandir($root);
        $this->assertFalse(in_array("${TOKEN2}_big.png",$files));
        $this->assertTrue(in_array('index.html',$files));
    }

    public function testDeleteMapCache() {
        
        global $TOKEN1,$TOKEN2;

        $root = realpath(__DIR__) . "/../../app/maps";

        # Delete first token
        delete_map_cache("${TOKEN1}");
        $files = scandir($root);

        $this->assertFalse(in_array("${TOKEN1}_zoom.jpg",$files));
        $this->assertTrue(in_array("${TOKEN2}_zoom.jpg",$files));
        $this->assertTrue(in_array('index.html',$files));

        # Delete second token
        delete_map_cache("${TOKEN2}");
        $files = scandir($root);
        $this->assertFalse(in_array("${TOKEN2}_zoom.png",$files));
        $this->assertTrue(in_array('index.html',$files));
    }

    public function testRemoveEmoji() {
        $regexSymbols = '😀🙏';
        $this->assertSame(removeEmoji($regexSymbols."TEST".$regexSymbols."TEST".$regexSymbols),"TESTTEST");

        $regexSymbols = '🌀🛿';
        $this->assertSame(removeEmoji($regexSymbols."TEST".$regexSymbols."TEST".$regexSymbols),"TESTTEST");

        $regexSymbols = '🚀🛿';
        $this->assertSame(removeEmoji($regexSymbols."TEST".$regexSymbols."TEST".$regexSymbols),"TESTTEST");

        $regexSymbols = '🇠🇿';
        $this->assertSame(removeEmoji($regexSymbols."TEST".$regexSymbols."TEST".$regexSymbols),"TESTTEST");
    }
}