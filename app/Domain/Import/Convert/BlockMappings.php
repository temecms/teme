<?php

/**
 * The block mapping registry (I-349): core's rows, and those plugins tag
 * under TAG. A block finds its row by its exact name, then by its
 * namespace (`canvas/*`); two rows for one name are refused when the
 * registry is first asked.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert;

use App\Domain\Import\Convert\Blocks\Buttons;
use App\Domain\Import\Convert\Blocks\Dropped;
use App\Domain\Import\Convert\Blocks\Embeds;
use App\Domain\Import\Convert\Blocks\Faq;
use App\Domain\Import\Convert\Blocks\Files;
use App\Domain\Import\Convert\Blocks\HowTo;
use App\Domain\Import\Convert\Blocks\Images;
use App\Domain\Import\Convert\Blocks\Layout;
use App\Domain\Import\Convert\Blocks\Lists;
use App\Domain\Import\Convert\Blocks\Markup;
use App\Domain\Import\Convert\Blocks\Players;
use App\Domain\Import\Convert\Blocks\Prose;
use App\Domain\Import\Convert\Blocks\Reusable;
use App\Domain\Import\Convert\Blocks\Tables;
use Illuminate\Contracts\Container\Container;
use LogicException;

final class BlockMappings
{
    // Later (I-349): BlockMapping and its context's contract move to Teme's contracts package, free of Illuminate.
    public const string TAG = 'teme.import.blocks';

    /** Core's rows (I-335). */
    public const array CORE = [
        Prose::class,
        Lists::class,
        Tables::class,
        Images::class,
        Embeds::class,
        Buttons::class,
        Layout::class,
        Dropped::class,
        Faq::class,
        HowTo::class,
        Reusable::class,
        Players::class,
        Files::class,
        Markup::class,
    ];

    /** @var array<string, BlockMapping>|null */
    private ?array $rows = null;

    public function __construct(private readonly Container $container) {}

    /**
     * The row that converts a block of this name, or null when none does.
     */
    public function find(string $name): ?BlockMapping
    {
        $rows = $this->rows ??= $this->index();
        $name = strtolower($name);
        $namespace = strstr($name, '/', true);

        return $rows[$name] ?? ($namespace === false ? null : ($rows["{$namespace}/*"] ?? null));
    }

    /**
     * @return array<string, BlockMapping>
     */
    private function index(): array
    {
        $rows = [];
        $mappings = [
            ...array_map(fn (string $class): mixed => $this->container->make($class), self::CORE),
            ...iterator_to_array($this->container->tagged(self::TAG), false),
        ];

        foreach ($mappings as $mapping) {
            if (! $mapping instanceof BlockMapping) {
                throw new LogicException('Every block mapping implements '.BlockMapping::class.'.');
            }

            foreach ($mapping->blocks() as $name) {
                if (preg_match('#^[a-z][a-z0-9-]*/(\*|[a-z][a-z0-9-]*)$#', $name) !== 1) {
                    throw new LogicException("A block mapping’s name [{$name}] must be namespace/name or namespace/*, in lower case.");
                }

                if (isset($rows[$name])) {
                    throw new LogicException(sprintf('Two block mappings convert [%s]: %s and %s.', $name, $rows[$name]::class, $mapping::class));
                }

                $rows[$name] = $mapping;
            }
        }

        return $rows;
    }
}
