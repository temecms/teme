<?php

/**
 * What a person asks to restore: from which place (one of this site's, or
 * one named on a new server's command line with the key that opens it),
 * which backup, and whether a site with data may be replaced without a
 * safety backup. Its key is never shown, stored or queued.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use App\Domain\Backups\NewPlace;
use App\Domain\Backups\RecoveryKey;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

final class RestoreRequest
{
    public function __construct(
        public readonly ?string $place = null,
        public readonly ?string $runId = null,
        public readonly bool $withoutSafetyBackup = false,
        public readonly ?NewPlace $from = null,
        #[SensitiveParameter] private readonly ?string $key = null,
    ) {
        if (($from === null) !== ($key === null)) {
            throw new LogicException('A restore’s key comes with the place it opens.');
        }
    }

    /**
     * The key that opens a place named on the command line.
     */
    public function key(): ?string
    {
        return $this->key;
    }

    /**
     * restic's password from what a person typed or a file held: a recovery
     * key, read as RecoveryKey::fromTyped() reads one, or the machine key's
     * 64 hexadecimal characters.
     *
     * @throws ValidationException
     */
    public static function keyFrom(#[SensitiveParameter] string $typed): string
    {
        $typed = trim($typed);

        if (preg_match('/^[0-9A-Fa-f]{64}$/', $typed) === 1) {
            return strtolower($typed);
        }

        try {
            return RecoveryKey::fromTyped($typed)->value();
        } catch (ValidationException) {
            throw ValidationException::withMessages(['key' => 'That isn’t a recovery key or a machine key.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['place' => $this->place, 'runId' => $this->runId, 'withoutSafetyBackup' => $this->withoutSafetyBackup, 'from' => $this->from?->name(), 'key' => $this->key === null ? null : '[hidden]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A restore is never queued or stored: its key would go with it.');
    }
}
