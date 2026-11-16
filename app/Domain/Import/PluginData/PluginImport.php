<?php

declare(strict_types=1);

namespace App\Domain\Import\PluginData;

use App\Domain\Plugins\Seams\PluginServices;
use ReflectionClass;
use ReflectionNamedType;
use Teme\Contracts\Import\WordPressImporter;
use Throwable;

/**
 * One running plugin granted import.wordpress, with what its manifest
 * declares it brings across from WordPress; its importer is built, with
 * only what Teme offers that plugin, when the import first asks for it.
 */
final class PluginImport
{
    private ?WordPressImporter $importer = null;

    /**
     * @param  array{importer: string, options: list<string>, postTypes: array<string, list<string>>, userMeta: list<string>, termMeta: list<string>}  $imports
     * @param  list<string>  $capabilities  what the plugin was granted
     * @param  list<string>  $hosts  the hosts its outbound door may reach
     */
    public function __construct(
        public readonly string $name,
        public readonly string $code,
        public readonly string $label,
        public readonly array $imports,
        private readonly array $capabilities,
        private readonly array $hosts,
        private readonly PluginServices $services,
    ) {}

    /**
     * Returns the plugin's importer, built as the plugin itself is (never
     * through the container), once.
     *
     * @throws PluginImportRefused when its class is missing, isn't an importer, or asks for what Teme doesn't offer the plugin
     */
    public function importer(): WordPressImporter
    {
        if ($this->importer !== null) {
            return $this->importer;
        }

        $class = $this->imports['importer'];

        if ($class === '' || ! class_exists($class)) {
            throw new PluginImportRefused("{$this->label}’s importer {$class} isn’t there.");
        }

        $offered = $this->services->for($this->name, $this->code, $this->capabilities, $this->hosts);
        $arguments = [];

        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $wanted = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
            $arguments[] = $offered[$wanted] ?? throw new PluginImportRefused("{$this->label}’s importer asks for {$wanted}, which Teme doesn’t offer it.");
        }

        try {
            $importer = new $class(...$arguments);
        } catch (Throwable $failure) {
            report($failure);

            throw new PluginImportRefused("{$this->label}’s importer couldn’t start. The application log has the details.");
        }

        if (! $importer instanceof WordPressImporter) {
            throw new PluginImportRefused("{$this->label}’s importer {$class} doesn’t implement Teme\\Contracts\\Import\\WordPressImporter.");
        }

        return $this->importer = $importer;
    }
}
