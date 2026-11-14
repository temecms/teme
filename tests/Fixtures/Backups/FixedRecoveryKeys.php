<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Backups\RecoveryKey;
use App\Domain\Backups\RecoveryKeySource;

/**
 * One recovery key and two positions, the same every time, so a test can
 * type the groups back at a command (I-2401).
 */
final readonly class FixedRecoveryKeys implements RecoveryKeySource
{
    public const string KEY = '7KQM-2VXD-9HTR-4BNC-WP6E-JF3S-YA8G-0ZMK';

    /** @param array{int, int} $positions */
    public function __construct(private array $positions = [3, 7]) {}

    /** @param array{int, int} $positions */
    public static function bind(array $positions = [3, 7]): void
    {
        app()->instance(RecoveryKeySource::class, new self($positions));
    }

    public function make(): RecoveryKey
    {
        return RecoveryKey::fromTyped(self::KEY);
    }

    /** @return array{int, int} */
    public function positionsToAsk(): array
    {
        return $this->positions;
    }
}
