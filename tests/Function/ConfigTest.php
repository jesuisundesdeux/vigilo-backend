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

require_once('app/config/config.php');

class ConfigTest extends TestCase
{
    public function testConfigurationExist() {
        global $config;
        $this->assertTrue(isset($config) != null);
    }


    /**
     * @depends testConfigurationExist
     */    
    public function testServerConfiguration()
    {
        global $config;

        $this->assertTrue(isset($config['URLBASE']) != null);
        $this->assertTrue(isset($config['HTTP_PROTOCOL']) != null);
        $this->assertTrue(isset($config['VIGILO_NAME']) != null);
        $this->assertTrue(isset($config['VIGILO_LANGUAGE']) != null);
    }

    /**
     * @depends testConfigurationExist
     */    
    public function testmysqlConfiguration()
    {
        global $config;

        $this->assertTrue(isset($config['MYSQL_HOST']) != null);
        $this->assertTrue(isset($config['MYSQL_USER']) != null);
        $this->assertTrue(isset($config['MYSQL_PASSWORD']) != null);
        $this->assertTrue(isset($config['MYSQL_DATABASE']) != null);
    }

    /**
     * @depends testConfigurationExist
     */    
    public function testMapConfiguration()
    {
        global $config;

        $this->assertTrue(isset($config['MAPQUEST_API']) != null);
    }

    /**
     * @depends testConfigurationExist
     */    
    public function testTwitterGlobalConfiguration()
    {
        global $config;

        $this->assertTrue(isset($config['APPROVE_TWITTER_EXPTIME']) != null);
     }
}
