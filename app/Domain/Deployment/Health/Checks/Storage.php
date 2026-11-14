<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\HealthFolders;
use App\Domain\Deployment\Health\Severity;

/**
 * Says whether every folder Teme writes to while it runs can be written
 * (I-378): the same folders /up checks, but naming each one that can't.
 */
final readonly class Storage implements DoctorCheck
{
    public function title(): string
    {
        return 'Storage';
    }

    public function examine(): array
    {
        $unwritable = HealthFolders::unwritable();

        if ($unwritable === []) {
            return [new Finding(Severity::Ok, 'Every folder Teme writes to can be written: '.implode(', ', array_map($this->shown(...), HealthFolders::paths())).'.')];
        }

        return array_map(
            fn (string $path): Finding => new Finding(Severity::Error, "{$this->shown($path)} can't be written.", 'Give the user PHP runs as write access to it; in the image, mount the volume the recipe names there.'),
            $unwritable,
        );
    }

    /**
     * A path relative to the application when it is inside it.
     */
    private function shown(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
