<?php
/*
 * Generates the ed25519 key pair used to sign the update archives.
 *
 *   php scripts/release-keygen.php
 *
 * - the secret key goes to the GitHub secret VIGILO_RELEASE_SIGNING_KEY (never commit it)
 * - the public key goes to app/includes/release_key.php (VIGILO_RELEASE_PUBLIC_KEY)
 *
 * Once a public key is published, the instances refuse unsigned archives: keep the secret
 * key safe, losing it means publishing a new public key by a manual update.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$pair = sodium_crypto_sign_keypair();
echo "VIGILO_RELEASE_SIGNING_KEY (secret) :\n" . base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n\n";
echo "VIGILO_RELEASE_PUBLIC_KEY (public) :\n" . base64_encode(sodium_crypto_sign_publickey($pair)) . "\n";
