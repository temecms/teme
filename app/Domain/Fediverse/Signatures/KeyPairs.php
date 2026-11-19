<?php

/**
 * Makes a new RSA key pair for one of the site's fediverse accounts, as
 * Mastodon and the WordPress plugin use: 2048 bits, both halves PEM.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Signatures;

use RuntimeException;

final class KeyPairs
{
    /**
     * A new key pair: the public half to publish, the private half to encrypt and store.
     *
     * @return array{public: string, private: string}
     */
    public function make(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false || ! openssl_pkey_export($key, $private)) {
            throw new RuntimeException('The server could not make a key pair.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ! is_string($private)) {
            throw new RuntimeException('The server could not make a key pair.');
        }

        return ['public' => (string) $details['key'], 'private' => $private];
    }
}
