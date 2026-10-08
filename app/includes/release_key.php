<?php
/*
 * Public key (ed25519, base64) of the Vigilo releases.
 *
 * When set, the admin updater only installs an archive signed with the matching secret
 * key (GitHub secret VIGILO_RELEASE_SIGNING_KEY of the release workflow). When empty,
 * the archive is checked against the SHA-256 published with the release.
 *
 * Generate a key pair with: php scripts/release-keygen.php
 */
define('VIGILO_RELEASE_PUBLIC_KEY', '');
