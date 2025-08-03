<?php

namespace Migration;

/**
 * Manage state of a migration instance.
 * Helpers class, managing $_SESSION in one place.
 * Save constants into $_SESSION variable to allow "statefullness" through
 * migration steps in a stateless context (consecutive HTTP calls).
 */
class State extends Common
{
  private $instance;
  private $constPrefix;
  private const SESSION_KEY = 'migration_state';

  /**
   * Define instance name and constant prefix.
   * Instance name is use for target working folder.
   * Each constants name are prefixed with $contPrefix with append method  .
   * @param string $constPrefix string appended in front of constant name
   */
  public function __construct($constPrefix = 'MGRT_')
  {
    $this->instance = date('Ymd-H:i:s');
    $this->constPrefix = $constPrefix;
  }

  /**
   * Initialize migration state through $_SESSION.
   * Remember, in this method migration plan just starts.
   */
  public function initialize()
  {
    $this->append('instance_d', self::WORKD() . $this->instance . '/');
    // create working folder
    if (!is_dir(MGRT_instance_d)) {
      mkdir(MGRT_instance_d, 0770, true);
    }
    // Load backend version
    if (!defined('BACKEND_VERSION')) {
      require self::ROOTD() . 'includes/common.php';
    }
    // Save current version
    $this->append('backend_version_before', BACKEND_VERSION);
  }

  /**
   * Set value of an already set $_SESSION key.
   * @param string $key $_SESSION key name
   * @param string $value $_SESSION[$key]=$value
   */
  public function set(string $keyName, string $value)
  {
    if (isset($_SESSION[$keyName])) {
      $_SESSION[$keyName] = $value;
    }
  }

  /**
   * Append constant variable into state file.
   * Constant name will be prefixed with 'MGRT_'.
   * @param string $constName constant variable name
   * @param mixed $constValue constant variable value
   * @return void
   */
  public function append(string $constName, mixed $constValue): void
  {
    $constant = $this->constPrefix . $constName;
    $_SESSION[self::SESSION_KEY][$constant] = $constValue;
    // also append it to current step
    if (!defined($constant)) {
      define($constant, $constValue);
    }
  }

  /**
   * Check if migration state was initialized by checking if
   * $_SESSION[self::SESSION_KEY] exist and its not empty.
   * @return boolean true is initialize, false not initialize
   */
  private function isInitialize()
  {
    return (
      isset($_SESSION[self::SESSION_KEY])
      || empty($_SESSION[self::SESSION_KEY])
    );
  }

  /**
   * Define constants from state.
   * Before using it, initialize method must be called.
   */
  public function load()
  {
    if (!$this->isInitialize()) {
      throw new \ErrorException('Migration state must be initialize (State::initialize) before use.');
    }
    foreach ($_SESSION[self::SESSION_KEY] as $constant => $value) {
      define($constant, $value);
    }
  }
}
