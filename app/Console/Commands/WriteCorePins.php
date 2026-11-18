<?php

/**
 * `teme:extensions:pins`: pins every package the image installed at its
 * exact version, for the extensions folder's composer.json, and writes them
 * to bootstrap/cache/teme-core-pins.php at image build
 * (docs/plans/plugin-installer.md). Nothing else writes that file.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Extensions\CorePins;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use RuntimeException;

final class WriteCorePins extends Command
{
    /** @var string */
    protected $signature = 'teme:extensions:pins
        {--no-dev : Leave out the packages installed only for development}
        {--path= : Where to write the pins, instead of bootstrap/cache}';

    /** @var string */
    protected $description = 'Pin the packages Teme was built with, for plugins and themes installed from the admin';

    public function handle(CorePins $pins): int
    {
        $installed = (Env::get('COMPOSER_VENDOR_DIR') ?: base_path('vendor')).'/composer/installed.json';
        $root = json_decode((string) @file_get_contents(base_path('composer.json')), true);
        $path = is_string($this->option('path')) && $this->option('path') !== '' ? $this->option('path') : base_path(CorePins::CACHE);

        try {
            $built = $pins->fromFile($installed, is_array($root) && is_string($root['name'] ?? null) ? $root['name'] : 'laravel/laravel', (bool) $this->option('no-dev'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $pins->write($built, $path)) {
            $this->components->error(__('Teme couldn’t write the core pins to :path.', ['path' => $path]));

            return self::FAILURE;
        }

        $this->components->info(__('Packages pinned: :count.', ['count' => count($built['replace'])]));

        return self::SUCCESS;
    }
}
