<?php

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

use InvalidArgumentException;

/**
 * An SSH server's public host key (I-2403), as ssh-keyscan reads it: the one
 * a person confirms by its fingerprint before Teme trusts the server, and
 * the line ssh then checks every connection against.
 */
final readonly class SshHostKey
{
    /** The key types Teme accepts, the one it prefers first. */
    public const array PREFERRED = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-rsa'];

    public function __construct(public string $type, public string $blob) {}

    /**
     * Reads 'type base64 [comment]', or ssh-keyscan's 'host type base64'.
     *
     * @throws InvalidArgumentException
     */
    public static function fromLine(string $line): self
    {
        $parts = preg_split('/\s+/', trim($line)) ?: [];

        if (count($parts) >= 3 && in_array($parts[1], self::PREFERRED, true)) {
            $parts = array_slice($parts, 1);
        }

        $type = $parts[0] ?? '';
        $bytes = base64_decode($parts[1] ?? '', true);

        if (! in_array($type, self::PREFERRED, true) || $bytes === false || ! str_starts_with($bytes, pack('N', strlen($type)).$type)) {
            throw new InvalidArgumentException('That isn’t an SSH public key.');
        }

        return new self($type, base64_encode($bytes));
    }

    /**
     * The key as known_hosts and authorized_keys write it, without a host or comment.
     */
    public function line(): string
    {
        return $this->type.' '.$this->blob;
    }

    /**
     * The fingerprint ssh-keygen -l and ssh itself show: SHA256, then the
     * unpadded base64 of the SHA-256 of the key's bytes.
     */
    public function fingerprint(): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) base64_decode($this->blob, true), true)), '=');
    }
}
