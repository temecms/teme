<?php

/**
 * What a place holds (I-364), asked of restic when a person asks, never when
 * the screen opens; it writes nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Queries;

use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticResult;
use Illuminate\Validation\ValidationException;

final readonly class PlaceStorage
{
    public function __construct(
        private BackupSetup $setup,
        private BackupKeys $keys,
        private Restic $restic,
    ) {}

    /**
     * The bytes the place in use with this ID holds, as restic's raw-data
     * statistics count them; or null and the sentence when restic couldn't say.
     *
     * @return array{bytes: int|null, sentence: string|null}
     */
    public function measure(string $placeId): array
    {
        $place = $this->setup->placesInUse()->firstWhere('id', $placeId)
            ?? throw ValidationException::withMessages(['place' => 'There’s no such place.']);
        $machine = $this->keys->machineKey();

        $result = PlaceRepositories::during(fn (PlaceRepositories $open): ResticResult => $this->restic->run(
            $open->repository($place, $machine),
            ['stats', '--mode', 'raw-data'],
            timeout: PlaceRepositories::ANSWER_SECONDS,
        ));

        $stats = $result->succeeded() ? $result->json() : null;

        if (! is_array($stats) || ! is_int($stats['total_size'] ?? null)) {
            return ['bytes' => null, 'sentence' => $result->sentence($place->name)];
        }

        return ['bytes' => $stats['total_size'], 'sentence' => null];
    }
}
