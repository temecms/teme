<?php

/**
 * The Ed25519 key pair Teme signs in to one SFTP place with (I-2403): made
 * with sodium, its private half written in OpenSSH's own unencrypted
 * format for ssh to read from a file that lives for one call, its public
 * half for a person to add to the server's authorized_keys. It hides its
 * private half when dumped and refuses to be serialized.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

final readonly class SftpKeyPair
{
    public const string COMMENT = 'teme-backup';

    private const string TYPE = 'ssh-ed25519';

    private const string MAGIC = "openssh-key-v1\0";

    /**
     * @param  string  $secret  sodium's 64 bytes: the seed, then the public key
     * @param  string  $public  32 bytes
     */
    private function __construct(
        #[SensitiveParameter] private string $secret,
        private string $public,
    ) {}

    public static function generate(): self
    {
        $pair = sodium_crypto_sign_keypair();

        return new self(sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair));
    }

    /**
     * Reads back a private key privateKey() wrote, as kept in a place's
     * credentials.
     *
     * @throws InvalidArgumentException
     */
    public static function fromPrivateKey(#[SensitiveParameter] string $openSsh): self
    {
        $refuse = new InvalidArgumentException('That isn’t a private key Teme made.');
        $text = trim($openSsh);

        if (! str_starts_with($text, '-----BEGIN OPENSSH PRIVATE KEY-----') || ! str_ends_with($text, '-----END OPENSSH PRIVATE KEY-----')) {
            throw $refuse;
        }

        $body = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $text), true);

        if ($body === false || ! str_starts_with($body, self::MAGIC)) {
            throw $refuse;
        }

        $at = strlen(self::MAGIC);
        $cipher = self::read($body, $at);
        $kdf = self::read($body, $at);
        $kdfOptions = self::read($body, $at);
        $count = self::readInteger($body, $at);
        $publicBlob = self::read($body, $at);
        $private = self::read($body, $at);

        if ($cipher !== 'none' || $kdf !== 'none' || $kdfOptions !== '' || $count !== 1 || $publicBlob === null || $private === null || $at !== strlen($body)) {
            throw $refuse;
        }

        $inner = 8;
        $type = self::read($private, $inner);
        $public = self::read($private, $inner);
        $secret = self::read($private, $inner);

        if (substr($private, 0, 4) !== substr($private, 4, 4) || $type !== self::TYPE
            || $public === null || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $secret === null || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || $publicBlob !== self::string(self::TYPE).self::string($public)
            || ! hash_equals($public, sodium_crypto_sign_publickey_from_secretkey($secret))) {
            throw $refuse;
        }

        return new self($secret, $public);
    }

    /**
     * The private half in OpenSSH's own format, the same text every time.
     */
    public function privateKey(): string
    {
        // Any four bytes, twice: OpenSSH's check that a passphrase decrypted the
        // key. This one has none, so it is taken from the public key.
        $check = substr(hash('sha256', $this->public, true), 0, 4);
        $private = $check.$check.self::string(self::TYPE).self::string($this->public).self::string($this->secret).self::string(self::COMMENT);

        for ($pad = 1; strlen($private) % 8 !== 0; $pad++) {
            $private .= chr($pad);
        }

        $body = self::MAGIC.self::string('none').self::string('none').self::string('').pack('N', 1)
            .self::string($this->blob()).self::string($private);

        return "-----BEGIN OPENSSH PRIVATE KEY-----\n".chunk_split(base64_encode($body), 70, "\n")."-----END OPENSSH PRIVATE KEY-----\n";
    }

    /**
     * The public half, as a line of authorized_keys.
     */
    public function publicKey(): string
    {
        return self::TYPE.' '.base64_encode($this->blob()).' '.self::COMMENT;
    }

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public function __debugInfo(): array
    {
        return ['publicKey' => $this->publicKey(), 'privateKey' => '[hidden]'];
    }

    /**
     * @return array<never>
     */
    public function __serialize(): array
    {
        throw new LogicException('A key pair is never serialized.');
    }

    /**
     * @param  array<mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A key pair is never serialized.');
    }

    private function blob(): string
    {
        return self::string(self::TYPE).self::string($this->public);
    }

    /**
     * SSH's string: its length as four bytes, then its bytes.
     */
    private static function string(string $bytes): string
    {
        return pack('N', strlen($bytes)).$bytes;
    }

    /**
     * Reads one SSH string at $at and moves past it; null when the bytes run out.
     */
    private static function read(string $bytes, int &$at): ?string
    {
        $length = self::readInteger($bytes, $at);

        if ($length === null || strlen($bytes) < $at + $length) {
            return null;
        }

        $value = substr($bytes, $at, $length);
        $at += $length;

        return $value;
    }

    private static function readInteger(string $bytes, int &$at): ?int
    {
        if (strlen($bytes) < $at + 4) {
            return null;
        }

        $unpacked = unpack('N', substr($bytes, $at, 4));
        $at += 4;

        return is_array($unpacked) && is_int($unpacked[1] ?? null) ? $unpacked[1] : null;
    }
}
