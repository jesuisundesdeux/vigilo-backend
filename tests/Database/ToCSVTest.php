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
    realpath(__DIR__ . '/..'),
    get_include_path()
)));

require_once(realpath("app/includes/common.php"));
require_once(realpath("app/to_csv_live.php"));

class DatabaseTest extends TestCase
{
    public static function setUpBeforeClass() : void
    {
    }

    public static function tearDownAfterClass() : void
    {
    }

    public function testToCsvGetDatasWithoutOptions()
    {
        $tocsv = new ToCSV();

        $result = $tocsv->getDatas();
        $this->assertSame($result[0], "lat,long,rue,comment,categorie,token,time\n");
        $this->assertEquals(count($result), 11); # 10 + 1 header
    }

    public function testToCsvSetCategorieWithNotNumericValue()
    {
        $tocsv = new ToCSV();

        $this->expectExceptionMessage('is not numeric value');
        $tocsv->setCategorie("ABC");
    }

    public function testToCsvSetTimeWithNotNumericValue()
    {
        $tocsv = new ToCSV();

        $this->expectExceptionMessage('is not numeric value');
        $tocsv->setTime("ABC");
    }

    public function testToCsvGetDatasByCategorie()
    {
        $tocsv = new ToCSV();

        $tocsv->setCategorie(2);
        $result = $tocsv->getDatas();
        $this->assertEquals(count($result), 6);
    }

    public function testToCsvGetDatasByTime()
    {
        $tocsv = new ToCSV();

        $tocsv->setTime(1554454520);

        $result = $tocsv->getDatas();
        $this->assertEquals(count($result), 3);
    }

    public function testToCsvGetDatasByCategorieAndTime()
    {
        $tocsv = new ToCSV();

        $tocsv->setCategorie(4);
        $tocsv->setTime(1554463189);

        $result = $tocsv->getDatas();
        $this->assertEquals(count($result), 2);
    }
}