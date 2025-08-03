<?php

namespace Migration;

/**
 * Fake class simulate migration plan and doing nothing.
 * For debug purposes only.
 */
class FakePlan extends Plan
{
  public function fake_getTarball()
  {
    return [0, 'FAKE ' . __FUNCTION__];
  }

  public function fake_extractApp()
  {
    return [0, 'FAKE ' . __FUNCTION__];
  }

  public function fake_dbUpdate()
  {
    return [0, 'FAKE ' . __FUNCTION__];
  }

  public function fake_dbBackup()
  {
    return [0, 'FAKE ' . __FUNCTION__];
  }

  public function run($step)
  {
    $action = array_keys($this->steps)[$step];
    $status = array_merge(call_user_func([$this, 'fake_' . $action]), [$action, $this->state($step)]);
    $this->randomSleep(500, 2000);
    return $status;
  }

  /**
   * Wait for a random value between $min and $max ; parameters in milliseconds.
   * For debug purpose only.
   * @param int $min minimum value
   * @param int $max maxiumum value
   */
  private function randomSleep(int $min = 1000, int $max = 2000)
  {
    sleep(random_int($min, $max) * 1000);
  }
}
