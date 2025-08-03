<?php

const MIGRATION_AL_REPLACE = [
  '/^I([A-Z])/' => 'interface_\1',  // interface prefix
  '/([a-z])([A-Z])/' => '\1_\2',    // modify MigrationPlanState to Migration_Plan_State
  '!\\\\!' => '/',                  // backslash to slash
];

/**
 * @param string $class fully-qualified class name
 */
spl_autoload_register(function ($class) {
  $filename = __DIR__ . '/' .
    strtolower(
      preg_replace(
        array_keys(MIGRATION_AL_REPLACE),
        array_values(MIGRATION_AL_REPLACE),
        $class
      )
    ) . '.php';
  if (file_exists($filename)) {
    require $filename;
  }
});
