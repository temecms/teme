<?php

declare(strict_types=1);

namespace Fixtures\MarkerPlugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * Writes a marker beside the project when Composer runs it, which the
 * installer must never let happen.
 */
final class Marker implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        file_put_contents(getcwd().'/composer-plugin-ran', 'ran');
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}
}
