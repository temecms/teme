<?php

/**
 * The APP_KEY a backup was made under, and its previous keys, read from the
 * backup into memory only (I-359): never shown, never stored, never queued.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

final class BackupAppKey
{
    /**
     * @param  list<string>  $keys  the key first, then its previous keys
     */
    private function __construct(#[SensitiveParameter] private readonly array $keys) {}

    /**
     * From app-key.json as the backup holds it: {"key": …, "previous_keys": […]}.
     *
     * @param  array<string, mixed>  $file
     */
    public static function fromArray(#[SensitiveParameter] array $file): self
    {
        $key = $file['key'] ?? null;
        $previous = $file['previous_keys'] ?? [];

        if (! is_string($key) || $key === '' || ! is_array($previous)) {
            throw new InvalidArgumentException('That isn’t a backup’s APP_KEY.');
        }

        return new self(array_values(array_unique([$key, ...array_filter($previous, fn (mixed $one): bool => is_string($one) && $one !== '')])));
    }

    /**
     * The key, then its previous keys.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return $this->keys;
    }

    /**
     * Whether this server can read what the backup encrypted: each of its
     * keys is this server's APP_KEY or one of its previous keys.
     */
    public function opensHere(): bool
    {
        $here = [(string) config('app.key'), ...array_filter((array) config('app.previous_keys'), is_string(...))];

        return array_diff($this->keys, $here) === [];
    }

    /**
     * app-key.json as the backup holds it, for re-encrypting its secrets in memory (I-359).
     */
    public function json(): string
    {
        return json_encode(['key' => $this->keys[0], 'previous_keys' => array_slice($this->keys, 1)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{keys: string}
     */
    public function __debugInfo(): array
    {
        return ['keys' => '[hidden]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A backup’s APP_KEY is never queued or stored.');
    }
}
