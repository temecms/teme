<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Extensions\Sources\ComposerSource;
use App\Domain\Extensions\Sources\GitSource;
use App\Domain\Extensions\Sources\SourceKinds;
use App\Domain\Extensions\Sources\UploadSource;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the package sources beyond Packagist that the installer reads
 * from: a Git address, a vendor's Composer source, and an uploaded ZIP (I-211).
 */
final class ExtensionSourcesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([GitSource::class, ComposerSource::class, UploadSource::class], SourceKinds::TAG);
    }
}
