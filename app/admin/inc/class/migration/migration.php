<?php

namespace Migration;

/**
 * Remember this class is intended to be called in a stateless context.
 * Corresponding migration step is pick from $_SESSION.
 */
class Migration
{
  private Plan $plan;
  private array $status = ['errno' => 1, 'errno_msg' => false, 'step' => '__not_defined__'];

  public function __construct(Plan $plan)
  {
    $this->plan = $plan;
  }

  /**
   * Set migration step status, send json response and stop processing.
   */
  private function sendResult(int $errno, string $message, string $stepName, $state)
  {
    $this->status['errno'] = $errno;
    $this->status['errno_msg'] = $message;
    $this->status['step'] = $stepName;
    $this->status['state'] = $state;

    // save next migration action step name into $_SESSION or finished
    // TODO: fix in right place, it's not the right place to do that
    if ($stepName === array_key_last($this->plan->steps)) {
      $this->plan->state->set('migration', 'available');
    } else {
      $this->plan->state->set('migration', $stepName);
    }

    // HTTP response body with status array
    echo json_encode($this->status);

    // stop script execution
    exit(0);
  }

  public function migrate()
  {
    // Be sure current migration step is valid or not the last one. Because
    // current migration step become from $_SESSION, it can't be the last one.
    if (($_SESSION['migration'] !== 'available'
        && !in_array($_SESSION['migration'], array_keys($this->plan->steps)))
        || $_SESSION['migration'] === array_key_last($this->plan->steps)
    ) {
      // sendResult stop script execution
      $this->sendResult(1, 'migration step invalid: "' . $_SESSION['migration'] . '"', 'invalid', 'ended');
    }

    // define current migration step name
    if ($_SESSION['migration'] === 'available') {
      // migration just starts
      $step = 0;
      $this->plan->state->initialize();
    } else {
      // load state constant variable
      $this->plan->state->load();
      // pick next step, in $_SESSION this is previous one, so adding 1 to it
      $step = array_search($_SESSION['migration'], array_keys($this->plan->steps)) + 1;
    }

    // run corresponding migration step function
    $stepStatus = $this->plan->runStep($step);
    call_user_func_array([$this, 'sendResult'], $stepStatus);
  }
}
