<?php

/**
 * Serves a plugin's declared script itself: finds the file a manifest declares under
 * contributes.scripts, with the same package checks as a stylesheet (a plain file inside the
 * package, no link on its path), and gives its text with a hash of its content as its version.
 * A script is served as it is; Teme does not wrap or rewrite it.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Scripts;

use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Styles\PluginStylesheet;
use InvalidArgumentException;

final class PluginScriptFile
{
    public function __construct(
        private readonly PluginRecords $records,
        private readonly PluginRegistry $registry,
        private readonly PluginStylesheet $files,
    ) {}

    /**
     * The text and the hash of an enabled plugin's declared script, or null when the plugin is not
     * enabled, the file is not declared, or its package is missing. A link on the path, or a file
     * outside the package, is refused with an InvalidArgumentException.
     *
     * @return array{name: string, hash: string, text: string}|null
     *
     * @throws InvalidArgumentException
     */
    public function read(string $code, string $file): ?array
    {
        $name = $this->nameOf($code);

        if ($name === null || ! $this->declares($name, $file)) {
            return null;
        }

        $source = $this->files->source($name, $file);

        if ($source === null) {
            return null;
        }

        $text = (string) file_get_contents($source);

        return ['name' => $name, 'hash' => substr(hash('sha256', $text), 0, 12), 'text' => $text];
    }

    /**
     * The name of the enabled plugin with this code, or null when none is enabled.
     */
    private function nameOf(string $code): ?string
    {
        $name = null;

        foreach ($this->records->enabled() as $record) {
            if ((string) $record->code === $code) {
                $name = (string) $record->name;
            }
        }

        return $name;
    }

    /**
     * Whether the plugin's manifest declares this file under contributes.scripts.
     */
    private function declares(string $name, string $file): bool
    {
        $manifest = $this->registry->all()[$name]->manifest ?? null;

        return $manifest !== null && in_array($file, array_column(DeclaredScripts::entries($manifest->contributes()), 'file'), true);
    }
}
