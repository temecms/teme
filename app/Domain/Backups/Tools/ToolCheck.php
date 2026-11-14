<?php

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

/**
 * What Teme found of one program: where it looked, where the program is, and the version it said; null for what it couldn't find or read.
 */
final readonly class ToolCheck
{
    public function __construct(
        public string $name,
        public string $looked,
        public ?string $path,
        public ?string $version,
    ) {}

    /**
     * The major version: 18 for 18.6, 0 for restic's 0.19.1; null when unread.
     */
    public function major(): ?int
    {
        return $this->version === null ? null : (int) $this->version;
    }
}
