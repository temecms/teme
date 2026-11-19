<?php

/**
 * Registers the package sources beyond Packagist that the installer reads
 * from: a Git address, a vendor's Composer source, an uploaded ZIP, and
 * Teme's plugin and theme directory, whose settings group it declares too.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Extensions\Directory\DirectorySettings;
use App\Domain\Extensions\Sources\ComposerSource;
use App\Domain\Extensions\Sources\DirectorySource;
use App\Domain\Extensions\Sources\GitSource;
use App\Domain\Extensions\Sources\SourceKinds;
use App\Domain\Extensions\Sources\UploadSource;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\SettingsRegistrar;

final class ExtensionSourcesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([GitSource::class, ComposerSource::class, UploadSource::class, DirectorySource::class], SourceKinds::TAG);
    }

    public function boot(): void
    {
        $this->app->make(SettingsRegistrar::class)->group(DirectorySettings::group());
    }
}
