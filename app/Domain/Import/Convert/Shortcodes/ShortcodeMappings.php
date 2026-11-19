<?php

/**
 * The shortcode mapping registry (I-349): core's rows, and those plugins tag
 * under TAG. A shortcode finds its row by its exact name; two rows for one
 * name are refused when the registry is first asked.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Shortcodes;

use Illuminate\Contracts\Container\Container;
use LogicException;

final class ShortcodeMappings
{
    public const string TAG = 'teme.import.shortcodes';

    /** Core's rows (I-336). */
    public const array CORE = [
        Captions::class,
        Galleries::class,
        MediaShortcodes::class,
        EmbedShortcode::class,
    ];

    /** @var array<string, ShortcodeMapping>|null */
    private ?array $rows = null;

    public function __construct(private readonly Container $container) {}

    /**
     * The row that converts a shortcode of this name, or null when none does.
     */
    public function find(string $name): ?ShortcodeMapping
    {
        $rows = $this->rows ??= $this->index();

        return $rows[$name] ?? null;
    }

    /**
     * @return array<string, ShortcodeMapping>
     */
    private function index(): array
    {
        $rows = [];
        $mappings = [
            ...array_map(fn (string $class): mixed => $this->container->make($class), self::CORE),
            ...iterator_to_array($this->container->tagged(self::TAG), false),
        ];

        foreach ($mappings as $mapping) {
            if (! $mapping instanceof ShortcodeMapping) {
                throw new LogicException('Every shortcode mapping implements '.ShortcodeMapping::class.'.');
            }

            foreach ($mapping->shortcodes() as $name) {
                if (preg_match('/^[a-z0-9_][a-z0-9_-]*$/', $name) !== 1) {
                    throw new LogicException("A shortcode mapping’s name [{$name}] must be lower-case letters, digits, _ and -.");
                }

                if (isset($rows[$name])) {
                    throw new LogicException(sprintf('Two shortcode mappings convert [%s]: %s and %s.', $name, $rows[$name]::class, $mapping::class));
                }

                $rows[$name] = $mapping;
            }
        }

        return $rows;
    }
}
