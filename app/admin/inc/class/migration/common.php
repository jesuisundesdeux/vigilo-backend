<?php

namespace Migration;

/**
 * Class defining common constants, intended to be extends.
 */
abstract class Common
{
  private const WORKING_FOLDER = 'admin/upgrade/work/';
  private string $type;
  private bool $maintenance;

  public State $state;
  public array $steps;

  public const RELEASE_BASE_URL = 'https://api.github.com/repos/jesuisundesdeux/vigilo-backend/releases/%s';
  public const ARCHIVE_BASE_URL = 'https://codeload.github.com/jesuisundesdeux/vigilo-backend/tar.gz/refs/tags/%s';
  public const ARCHIVE_FILENAME = 'vigilo-backend-%s.tar.gz';
  public const SQL_DUMP_FILENAME = 'vigilo-backend.sql';

  public const STEPS = [];
  public const maintenance_steps = [
    'maintenance_on' => 'Activation du mode maintenance',
    'maintenance_off' => 'Désactivation du mode maintenance',
  ];

  /**
    * @param State $state corresponding migration state object
    * Share migration state through $this->state property.
    */
  public function __construct(string $type = 'UP', bool $maintenance = false)
  {
    $this->type = self::validateType($type);
    $this->maintenance = $maintenance;
    $this->state = new State();
    $this->steps = $this->getSteps($this->type, $this->maintenance);
  }

  protected static function ROOTD()
  {
    return $_SERVER['DOCUMENT_ROOT'];
  }

  protected static function WORKD()
  {
    return  self::ROOTD() . self::WORKING_FOLDER;
  }

  /**
   * Retrieve steps from migration plan with or without maintenance.
   * @return array migration steps k,v => function_name, description
   */
  public static function getSteps($type = 'UP', bool $maintenance = false, bool $json = false): array
  {
    $steps = static::STEPS[self::validateType($type)];
    if ($maintenance) {
      $steps = array_merge(
        array_slice(self::maintenance_steps, 0, 1),
        $steps,
        array_slice(self::maintenance_steps, 1, 2),
      );
    }
    // translation needed to use a Map object from javascript
    if ($json) {
      return array_map(function ($k, $v) {
        return [$k, $v];
      }, array_keys($steps), array_values($steps));
    }
    return $steps;
  }

  /**
   * Run a migration plan step.
   * @param string $step method name to be run
   */
  public function runStep($step)
  {
    // run corresponding migration step function
    $action = array_keys($this->steps)[$step];
    return array_merge(call_user_func([$this, $action]), [$action, $this->state($step)]);
  }

  public function state($step)
  {
    // $step starts at 0
    if ($step === 0) {
      // TODO: FIX!
      return "starts:{$step}:{$_SESSION['migration_state']['MGRT_backend_version_before']}";
    }
    if ($step === count($this->steps) - 1) {
      return "end:{$step}:{$_SESSION['migration_state']['MGRT_release_version']}";
    }
    return "running:{$step}";
  }

  /**
   * Be sure migration type has correct value.
   */
  public static function validateType($type)
  {
    if (in_array($type, array_keys(static::STEPS))) {
      return $type;
    }
    // invalid type
    $nofSteps = count(array_keys(static::STEPS));
    if ($nofSteps >= 2) {
      $quoted = array_map(function ($e) {
        return "'" . $e . "'";
      }, array_keys(static::STEPS));
      $last = array_pop($quoted);
      $error_message = 'Migration type must be : ' . implode(', ', $quoted) . " or {$last}";
    } elseif ($nofSteps < 1) {
      $error_message = 'Array Mine::STEPS constant must be defined and contain at least one plan.';
    } else { // non-sense, guardrail
      $error_message = "Migration type must be : '" . array_keys(static::STEPS)[0] . "'";
    }
    throw new Error($error_message);
  }

  /**
   * Helper function to retrieve JSON release information file by giving is name,
   * default 'latest'.
   */
  public static function getReleaseUrl(string $filename = 'latest'): string
  {
    return sprintf(static::RELEASE_BASE_URL, $filename);
  }

  /**
   * Helper function to retrieve package archive URL by giving its version.
   */
  public static function getArchiveUrl(string $version): string
  {
    return sprintf(static::ARCHIVE_BASE_URL, $version);
  }
}
