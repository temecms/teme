<?php

/**
 * Wires media: the image engine on the configured driver, the media policy,
 * and the Media settings group.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Media\Images\ImageEngine;
use App\Domain\Media\Images\InterventionImageEngine;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Policies\MediaPolicy;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsRegistrar;

final class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImageEngine::class, fn (): ImageEngine => new InterventionImageEngine((string) config('media.image_driver', 'gd')));
    }

    public function boot(): void
    {
        Gate::policy(Media::class, MediaPolicy::class);

        // php artisan dev starts the long queue's worker beside the default one.
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('queue:listen database-long --queue=long --tries=1 --timeout=0', 'queue-long');
        }

        $this->app->make(SettingsRegistrar::class)->group(new SettingsGroup(
            key: 'media',
            label: 'Media',
            description: 'Limits on the files people can upload, and the image widths Teme makes.',
            section: 'media',
            ability: 'settings.media.manage',
            settings: [
                Setting::number('max_upload_mb', 'Largest upload', 'The biggest file, in megabytes, anyone can upload. Your server’s own upload limit must be at least this large.', '0.1.0')
                    ->default(64)->min(1)->max(2048),
                Setting::number('max_megapixels', 'Largest image', 'The most megapixels an uploaded image may have. It stops a small file that would expand to gigabytes in memory.', '0.1.0')
                    ->default(50)->min(1)->max(200),
            ],
            since: '0.1.0',
        ));
    }
}
