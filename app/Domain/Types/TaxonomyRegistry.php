<?php

/**
 * Every taxonomy: code's, registered as the application boots, then the
 * site's own, stored in the database and read with the stored types when
 * first asked for (I-414). It refuses one whose key or segment would break
 * storage or routing; a stored one it refuses is left out with its reason,
 * never thrown. It answers which taxonomies a content type uses.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types;

use App\Domain\Types\Exceptions\InvalidTaxonomyDefinition;
use App\Domain\Types\Exceptions\UnknownTaxonomy;
use InvalidArgumentException;

final class TaxonomyRegistry
{
    private const string KEY = '/^[a-z][a-z0-9_]{0,31}$/';

    private const string SEGMENT = '/^[a-z][a-z0-9-]{0,39}$/';

    /** @var array<string, Taxonomy> code's, then the stored ones */
    private array $taxonomies = [];

    /** @var array<string, Taxonomy> code's, in registration order */
    private array $declared = [];

    /** @var array<string, string> a stored taxonomy's key => why it isn't registered */
    private array $refused = [];

    /** Changes whenever a taxonomy is registered. */
    private string $stamp;

    public function __construct()
    {
        $this->stamp = bin2hex(random_bytes(8));
    }

    public function register(Taxonomy $taxonomy): void
    {
        // Code's types only: taxonomies register at boot, which reads no
        // database; a stored type is checked against taxonomies when read.
        $typeSegments = array_map(fn (ContentType $type): string => $type->segment, array_filter(app(ContentTypeRegistry::class)->fromCode(), fn (ContentType $type): bool => $type->listed));
        $problem = $this->problem($taxonomy, $typeSegments);

        // Nor an admin screen's name: the fixed screens, code's listed types and code's other taxonomies.
        $others = [
            ...array_map(fn (ContentType $type): string => $type->plural, array_values(array_filter(app(ContentTypeRegistry::class)->fromCode(), fn (ContentType $type): bool => $type->listed))),
            ...array_map(fn (Taxonomy $other): string => $other->plural, array_values(array_filter($this->declared, fn (Taxonomy $other): bool => $other->key !== $taxonomy->key))),
        ];
        $screen = AdminScreenNames::clash($taxonomy->plural, taxonomyKey: $taxonomy->key, typePlurals: $others);
        $problem ??= $screen === null ? null : "Taxonomy [{$taxonomy->key}] can't be called [{$taxonomy->plural}]: ".AdminScreenNames::sentence($screen, $taxonomy->singular, 'taxonomy');

        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        $this->declared[$taxonomy->key] = $taxonomy;
        $this->taxonomies[$taxonomy->key] = $taxonomy;
        $this->stamp = bin2hex(random_bytes(8));
    }

    /**
     * Called by ContentTypeRegistry when the stored rows change, before the
     * stored types are read: drops the stored taxonomies, registers each row
     * that parses and passes, and keeps why the rest don't. Reads no table.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $typeSegments  every listed type's segment: code's, and the stored type rows'
     * @param  list<string>  $codeTypes  the keys of code's listed types
     *
     * @return list<TaxonomyDefinition> the rows registered, oldest first
     */
    public function replaceStored(array $rows, array $typeSegments, array $codeTypes): array
    {
        $this->taxonomies = $this->declared;
        $this->refused = [];
        $accepted = [];

        foreach ($rows as $row) {
            $key = is_string($row['key'] ?? null) ? $row['key'] : '?';

            try {
                $definition = TaxonomyDefinition::fromArray($row)->settled();
            } catch (InvalidTaxonomyDefinition $invalid) {
                $this->refused[$key] = $invalid->getMessage();

                continue;
            }

            $problem = $this->problem($definition->toTaxonomy(), $typeSegments);

            foreach ($definition->codeTypes as $type) {
                if ($problem === null && ! in_array($type, $codeTypes, true)) {
                    $problem = "Taxonomy [{$definition->key}] is used by [{$type}], which isn’t a listed type code declares.";
                }
            }

            if ($problem !== null) {
                $this->refused[$key] = $problem;

                continue;
            }

            $this->taxonomies[$definition->key] = $definition->toTaxonomy();
            $accepted[] = $definition;
        }

        $this->stamp = bin2hex(random_bytes(8));

        return $accepted;
    }

    /**
     * What register() refuses $taxonomy for, with the stored rows read and
     * the taxonomy under $replacing set aside; null when it passes.
     */
    public function refusal(Taxonomy $taxonomy, ?string $replacing = null): ?string
    {
        $this->load();

        return $this->problem($taxonomy, app(ContentTypeRegistry::class)->segments(), $replacing);
    }

    /**
     * @param  list<string>  $typeSegments
     */
    private function problem(Taxonomy $taxonomy, array $typeSegments, ?string $replacing = null): ?string
    {
        if (preg_match(self::KEY, $taxonomy->key) !== 1) {
            return "Taxonomy key [{$taxonomy->key}] must be lower case letters, digits and underscores, starting with a letter, at most 32 characters.";
        }

        if (isset($this->taxonomies[$taxonomy->key]) && $taxonomy->key !== $replacing) {
            return "Taxonomy [{$taxonomy->key}] is already registered.";
        }

        if (preg_match(self::SEGMENT, $taxonomy->segment) !== 1) {
            return "Taxonomy [{$taxonomy->key}] has segment [{$taxonomy->segment}]: a segment is lower case letters, digits and hyphens, starting with a letter, at most 40 characters.";
        }

        if (in_array($taxonomy->segment, ContentTypeRegistry::RESERVED, true)) {
            return "Taxonomy [{$taxonomy->key}] cannot use the reserved segment [{$taxonomy->segment}].";
        }

        foreach ($this->taxonomies as $existing) {
            if ($existing->key !== $replacing && $existing->segment === $taxonomy->segment) {
                return "Taxonomy [{$taxonomy->key}] cannot use segment [{$taxonomy->segment}]: another taxonomy has it.";
            }
        }

        if (in_array($taxonomy->segment, $typeSegments, true)) {
            return "Taxonomy [{$taxonomy->key}] cannot use segment [{$taxonomy->segment}]: a content type has it.";
        }

        return null;
    }

    /**
     * Code's taxonomies, in registration order, without reading the stored
     * ones: what boot may ask.
     *
     * @return list<Taxonomy>
     */
    public function fromCode(): array
    {
        return array_values($this->declared);
    }

    /**
     * Whether a taxonomy is registered now, without reading the stored ones:
     * for the registries' own checks.
     */
    public function isRegistered(string $key): bool
    {
        return isset($this->taxonomies[$key]);
    }

    /**
     * The segments of what is registered now, without reading the stored
     * ones: for the registries' own checks.
     *
     * @return list<string>
     */
    public function registeredSegments(): array
    {
        return array_values(array_map(fn (Taxonomy $taxonomy): string => $taxonomy->segment, $this->taxonomies));
    }

    /**
     * The stored taxonomies left out, each with why.
     *
     * @return array<string, string>
     */
    public function refused(): array
    {
        $this->load();

        return $this->refused;
    }

    /**
     * A value that changes whenever a taxonomy is registered.
     */
    public function stamp(): string
    {
        $this->load();

        return $this->stamp;
    }

    /**
     * The taxonomy registered under $key.
     */
    public function get(string $key): Taxonomy
    {
        $this->load();

        return $this->taxonomies[$key] ?? throw UnknownTaxonomy::named($key);
    }

    public function has(string $key): bool
    {
        $this->load();

        return isset($this->taxonomies[$key]);
    }

    /**
     * Every taxonomy, in registration order.
     *
     * @return list<Taxonomy>
     */
    public function all(): array
    {
        $this->load();

        return array_values($this->taxonomies);
    }

    /**
     * The taxonomy whose admin screen lives at /admin/{$segment}, or null.
     */
    public function bySegment(string $segment): ?Taxonomy
    {
        $this->load();

        foreach ($this->taxonomies as $taxonomy) {
            if ($taxonomy->segment === $segment) {
                return $taxonomy;
            }
        }

        return null;
    }

    /**
     * Every taxonomy's key, in registration order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $this->load();

        return array_keys($this->taxonomies);
    }

    /**
     * Every taxonomy's segment, in registration order.
     *
     * @return list<string>
     */
    public function segments(): array
    {
        $this->load();

        return array_values(array_map(fn (Taxonomy $taxonomy): string => $taxonomy->segment, $this->taxonomies));
    }

    /**
     * The taxonomies a content type uses, in the order the type names them;
     * none for a type nobody registered.
     *
     * @return list<Taxonomy>
     */
    public function forType(string $typeKey): array
    {
        $this->load();

        $types = app(ContentTypeRegistry::class);

        if (! $types->has($typeKey)) {
            return [];
        }

        $taxonomies = [];

        foreach ($types->get($typeKey)->taxonomies as $key) {
            if (isset($this->taxonomies[$key])) {
                $taxonomies[] = $this->taxonomies[$key];
            }
        }

        return $taxonomies;
    }

    /**
     * The content types that use a taxonomy, in registration order.
     *
     * @return list<ContentType>
     */
    public function typesUsing(string $taxonomyKey): array
    {
        $this->load();

        return array_values(array_filter(
            app(ContentTypeRegistry::class)->all(),
            fn (ContentType $type): bool => in_array($taxonomyKey, $type->taxonomies, true),
        ));
    }

    /** Has the registry read the stored taxonomies, when they changed. */
    private function load(): void
    {
        app(ContentTypeRegistry::class)->readStored();
    }
}
