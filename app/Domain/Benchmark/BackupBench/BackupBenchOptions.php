<?php

declare(strict_types=1);

namespace App\Domain\Benchmark\BackupBench;

/**
 * The backup benchmark's sizes (I-367): the folder its place and key go in,
 * the items and images, each file's share of its size, the night's new
 * images, and the seeder's other counts.
 */
final readonly class BackupBenchOptions
{
    public function __construct(
        public string $folder,
        public int $items = 200000,
        public int $images = 40000,
        public float $scale = 0.25,
        public int $night = 40,
        public int $protectEvery = 1000,
        public int $comments = 40000,
        public int $redirects = 100000,
        public int $notFound = 100000,
        public int $aiActivity = 1000000,
    ) {}
}
