<?php

/**
 * teme:media:add — adds files from the server's disk to the media library, as
 * the operator, through UploadMedia. Used for quick checks and small imports.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\Exceptions\DuplicateMediaException;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Media\Operations\UploadMedia;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class AddMedia extends Command
{
    /** @var string */
    protected $signature = 'teme:media:add {paths* : Files to add} {--allow-duplicate : Add files the library already has}';

    /** @var string */
    protected $description = 'Add files to the media library';

    public function handle(UploadMedia $upload): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);
        $failed = 0;

        foreach ((array) $this->argument('paths') as $path) {
            try {
                $media = $upload->execute(new UploadMediaInput((string) $path, basename((string) $path), null, (bool) $this->option('allow-duplicate')));
                $this->info("{$path} → {$media->id}");
            } catch (ValidationException $exception) {
                $this->error("{$path}: ".collect($exception->errors())->flatten()->implode(' '));
                $failed++;
            } catch (DuplicateMediaException $exception) {
                $this->warn("{$path}: already in the library as {$exception->existingId}");
                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
