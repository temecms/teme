<?php

/**
 * A plugin's teme-plugin.json, read into typed getters (I-3353). It holds
 * what the file says even when ManifestValidator found problems, so a broken
 * plugin can still be named; a getter whose value is missing or of the wrong
 * type answers empty, and a list answers each value once.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\Support\HttpsLink;

final readonly class Manifest
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw) {}

    /**
     * The Composer package name, the plugin's identity, or '' when missing.
     */
    public function name(): string
    {
        return $this->string('name');
    }

    /**
     * The plugin's short code, for its settings group and addresses.
     */
    public function code(): string
    {
        return $this->string('code');
    }

    /**
     * The name people see.
     */
    public function label(): string
    {
        return $this->string('label');
    }

    /**
     * One sentence on what the plugin does.
     */
    public function description(): string
    {
        return $this->string('description');
    }

    /**
     * The plugin's own version, such as 1.2.0.
     */
    public function version(): string
    {
        return $this->string('version');
    }

    /**
     * The Teme versions the plugin works with, such as "^0.5", or '' when it doesn't say.
     */
    public function teme(): string
    {
        $requires = $this->raw['requires'] ?? null;

        return is_array($requires) && is_string($requires['teme'] ?? null) ? $requires['teme'] : '';
    }

    /**
     * The plugin's own class, as the manifest names it, without a leading backslash.
     */
    public function pluginClass(): string
    {
        return ltrim($this->string('plugin'), '\\');
    }

    /**
     * What the plugin asks to do, each once, in the file's order.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->strings('capabilities');
    }

    /**
     * The hosts the plugin asks to reach, each once, in the file's order.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        return $this->strings('hosts');
    }

    /**
     * The hooks the plugin listens for, by short name, each once, in the file's order.
     *
     * @return list<string>
     */
    public function hooks(): array
    {
        return $this->strings('hooks');
    }

    /**
     * What the plugin adds to Teme's lists, as the file says it.
     *
     * @return array<string, mixed>
     */
    public function contributes(): array
    {
        $contributes = $this->raw['contributes'] ?? null;

        /** @var array<string, mixed> */
        return is_array($contributes) && ! array_is_list($contributes) ? $contributes : [];
    }

    /**
     * The tables the plugin declares under owns.tables, each once, in the file's order.
     *
     * @return list<string>
     *
     * @since 0.8.0-alpha.1
     */
    public function ownsTables(): array
    {
        $owns = $this->raw['owns'] ?? null;
        $tables = is_array($owns) ? ($owns['tables'] ?? null) : null;

        return is_array($tables) ? array_values(array_unique(array_filter($tables, is_string(...)))) : [];
    }

    /**
     * The public routes the plugin declares, each as the file says it.
     *
     * @return list<array<string, mixed>>
     *
     * @since 1.0.0
     */
    public function routes(): array
    {
        $routes = $this->raw['routes'] ?? null;

        if (! is_array($routes) || ! array_is_list($routes)) {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return array_values(array_filter($routes, is_array(...)));
    }

    /**
     * What the plugin brings across from the WordPress site an import reads
     * (docs/architecture/plugins.md): its importer class, without a leading
     * backslash, and the names it declares, each once; empty when it
     * declares nothing or a part is of the wrong type.
     *
     * @return array{importer: string, options: list<string>, postTypes: array<string, list<string>>, userMeta: list<string>, termMeta: list<string>}
     *
     * @since 1.0.0
     */
    public function imports(): array
    {
        $imports = $this->raw['imports'] ?? null;
        $imports = is_array($imports) && ! array_is_list($imports) ? $imports : [];
        $names = fn (mixed $values): array => is_array($values) && array_is_list($values) ? array_values(array_unique(array_filter($values, is_string(...)))) : [];
        $postTypes = [];

        if (is_array($imports['postTypes'] ?? null) && ! array_is_list($imports['postTypes'])) {
            foreach ($imports['postTypes'] as $type => $meta) {
                $postTypes[(string) $type] = $names($meta);
            }
        }

        return [
            'importer' => is_string($imports['importer'] ?? null) ? ltrim($imports['importer'], '\\') : '',
            'options' => $names($imports['options'] ?? null),
            'postTypes' => $postTypes,
            'userMeta' => $names($imports['userMeta'] ?? null),
            'termMeta' => $names($imports['termMeta'] ?? null),
        ];
    }

    /**
     * Who wrote the plugin, or ''.
     */
    public function author(): string
    {
        return $this->string('author');
    }

    /**
     * The author’s website, or ''.
     *
     * @since 1.0.0
     */
    public function authorUrl(): string
    {
        return HttpsLink::or($this->string('author_url'));
    }

    /**
     * The plugin's home page, or ''.
     */
    public function homepage(): string
    {
        return HttpsLink::or($this->string('homepage'));
    }

    /**
     * The path of the plugin's icon inside its folder, as declared, or ''.
     * PackagePictures::find() says whether it is there.
     *
     * @since next
     */
    public function icon(): string
    {
        return $this->string('icon');
    }

    /**
     * The file as it was read.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    private function string(string $key): string
    {
        return is_string($this->raw[$key] ?? null) ? $this->raw[$key] : '';
    }

    /**
     * @return list<string>
     */
    private function strings(string $key): array
    {
        $values = $this->raw[$key] ?? null;

        return is_array($values) ? array_values(array_unique(array_filter($values, is_string(...)))) : [];
    }
}
