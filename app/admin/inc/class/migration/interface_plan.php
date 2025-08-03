<?php

namespace Migration;

interface IPlan
{
  // private const RELEASE_BASE_URL = 'https://api.github.com/repos/jesuisundesdeux/vigilo-backend/releases/%s';
  public const RELEASE_BASE_URL = 'https://vigilo-git.test/fake/%s';
  // private const ARCHIVE_BASE_URL = 'https://codeload.github.com/jesuisundesdeux/vigilo-backend/tar.gz/refs/tags/%s';
  public const ARCHIVE_BASE_URL = 'https://vigilo-git.test/fake/vigilo-backend-%s.tar.gz';
  public const ARCHIVE_FILENAME = 'vigilo-backend-%s.tar.gz';
  public const SQL_DUMP_FILENAME = 'vigilo-backend.sql';
  public const MIGRATE_TYPE = ['UP', 'DOWN'];

  public const STEPS = [
    'UP' => [
      'getTarball' => 'Téléchargement de la release',
      'extractApp' => "Extraction du code source de l'application",
      'dbBackup' => 'Sauvegarde de la base de données',
      'dbUpdate' => 'Mise à jour de la base de données',
      // 'deploy' => 'Copie des fichiers',
      // 'cleanup' => 'Nettoyage'
    ],
    'DOWN' => []
  ];
  public const maintenance_steps = [
    'maintenance_on' => 'Activation du mode maintenance',
    'maintenance_off' => 'Désactivation du mode maintenance',
  ];

  public function getTarball(): array;

  public function extractApp(): array;

  public function dbBackup(): array;

  public function dbUpdate(): array;

  public function cleanup(): array;

  public function maintenance_on(): array;

  public function maintenance_off(): array;

  public static function upgradeSteps($maintenance, bool $json): array;

  public static function downgradeSteps($maintenance, bool $json): array;

  public static function getSteps(bool $type = true, bool $maintenance = true, bool $json = false): array;
}
