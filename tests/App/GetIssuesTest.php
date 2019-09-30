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
require_once(realpath("app/get_issues.php"));

class GetIssueTest extends TestCase
{
    public static function setUpBeforeClass() : void
    {
    }

    public static function tearDownAfterClass() : void
    {
    }

    public function testExportSetStatusWithNotNumericValue()
    {
        $export = new GetIssues();

        $this->expectExceptionMessage('is not numeric value');
        $export->setStatus("ABC");
    }

    public function testExportSetTimefilterWithNotNumericValue()
    {
        $export = new GetIssues();

        $this->expectExceptionMessage('is not numeric value');
        $export->setTimefilter("ABC");
    }

    public function testExportSetCountWithNotNumericValue()
    {
        $export = new GetIssues();

        $this->expectExceptionMessage('is not numeric value');
        $export->setCount("ABC");
    }

    public function testExportSetOffsetWithNotNumericValue()
    {
        $export = new GetIssues();

        $this->expectExceptionMessage('is not numeric value');
        $export->setOffset("ABC");
    }

    public function testExportWithoutOptions()
    {
        $export = new GetIssues();
        $result = $export->getIssues();

        $this->assertEquals(count($result), 10);
    }

    public function testExportByCategorie()
    {
        $export = new GetIssues();
        $export->setCategorie(2);
        $result = $export->getIssues();

        $this->assertEquals(count($result), 5);
    }

    public function testExportByTime()
    {
        $export = new GetIssues();
        $export->setTimefilter(1554454520);
        $result = $export->getIssues();

        $this->assertEquals(count($result), 2);
    }

    public function testExportByStatus()
    {
        $export = new GetIssues();
        $export->setStatus(0);
        $result = $export->getIssues();

        $this->assertEquals(count($result), 10);
    }

    public function testExportByToken()
    {
        $export = new GetIssues();
        $export->setToken("4XUXXEUX");
        $result = $export->getIssues();

        $this->assertEquals(count($result), 1);
    }

    public function testExportByScope()
    {
        $export = new GetIssues();
        $export->setScope("34_montpellier");
        $result = $export->getIssues();

        $this->assertEquals(count($result), 10);
    }

    public function testExportWithPager()
    {
        $export = new GetIssues();
        $export->setCount(3);
        $result = $export->getIssues();

        $this->assertEquals(count($result), 3);
    }

    public function testGetLimitAndOffsetSqlQuery()
    {
      $export = new GetIssues();

      $result = $export->getLimitQuery(15, -1);
      $this->assertEquals($result, "LIMIT 15");

      $result = $export->getLimitQuery(3, 1);
      $this->assertEquals($result, "LIMIT 3 OFFSET 1");
    }

    public function testGetSqlQuery()
    {
        $export = new GetIssues();
        $export->setCount(3);
        $export->setScope("34_montpellier");
        $export->setToken("4XUXXEUX");
        $export->setCategorie(2);
        $export->setTimefilter(1554454520);
        $result = $export->getQuery();

        $this->assertEquals($result, "SELECT obs_token,
    obs_city,
    obs_cityname,
    obs_coordinates_lat,
    obs_coordinates_lon,
    obs_address_string,
    obs_comment,
    obs_explanation,
    obs_time,
    t_status.status_update_status AS obs_status,
    obs_categorie,
    obs_approved
FROM obs_list
LEFT JOIN ( SELECT status_update_obsid, status_update_status, MAX(status_update_time) FROM obs_status_update GROUP BY status_update_obsid) t_status ON t_status.status_update_obsid = obs_list.obs_id
WHERE obs_complete=1
 AND obs_categorie IN ('2') AND obs_time > 1554454520 AND obs_token = '4XUXXEUX' AND (obs_approved=0 OR obs_approved=1)
ORDER BY obs_time DESC
LIMIT 3");
    }
}
