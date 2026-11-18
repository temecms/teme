<?php

/**
 * What a node must hold to be written at all (I-703): for each type Google
 * gives a rich result, its required properties, each requirement met by any
 * one of the property paths it names. A node of such a type missing one is
 * left out of its page's graph; the page keeps the rest, and publishing is
 * never blocked. A pack adds its own types' requirements, stricter where
 * the manual-action test is.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Document;

use InvalidArgumentException;

final class RequiredProperties
{
    /** How many references one path follows, so references that loop can't hang it. */
    private const int HOPS = 5;

    private const string PATH = '/^[a-z][A-Za-z]*(\.[a-z][A-Za-z]*)*$/';

    /**
     * Google's, as read on 2026-09-08. An ImageObject needs only its file:
     * one without a credit is still its page's image, which Article reads
     * though Google's Image metadata leaves it out.
     */
    private const array GOOGLE = [
        'AccountingService' => [['name'], ['address']],
        'Event' => [['name'], ['startDate'], ['location.address']],
        'ImageObject' => [['contentUrl']],
        'LegalService' => [['name'], ['address']],
        'LocalBusiness' => [['name'], ['address']],
        'MedicalBusiness' => [['name'], ['address']],
        'Product' => [['name'], ['offers', 'review', 'aggregateRating']],
        'ProfessionalService' => [['name'], ['address']],
        'ProfilePage' => [['mainEntity.name']],
        'Recipe' => [['name'], ['image']],
        'Restaurant' => [['name'], ['address']],
        'Store' => [['name'], ['address']],
        'VideoObject' => [['name'], ['thumbnailUrl'], ['uploadDate']],
    ];

    /** @var array<string, list<list<string>>> */
    private array $required = self::GOOGLE;

    /**
     * Adds a type's requirements to any it has, as a pack does for the types
     * it brings.
     *
     * @param  list<list<string>>  $requirements  each met by any one of its property paths, such as `location.address`
     */
    public function add(string $type, array $requirements): void
    {
        foreach ($requirements as $requirement) {
            if ($requirement === [] || array_filter($requirement, fn (string $path): bool => preg_match(self::PATH, $path) !== 1) !== []) {
                throw new InvalidArgumentException("A requirement of [{$type}] must name one or more properties, such as name or location.address.");
            }

            if (! in_array($requirement, $this->required[$type] ?? [], true)) {
                $this->required[$type][] = $requirement;
            }
        }
    }

    /**
     * What the node lacks of what its types require: each unmet requirement,
     * as the paths any one of which would meet it; [] when it lacks nothing.
     * A path reads through a reference to the node it names.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $graph  the page's nodes by @id
     *
     * @return list<list<string>>
     */
    public function missing(array $node, array $graph): array
    {
        $missing = [];
        $types = $node['@type'] ?? [];

        foreach (is_array($types) ? $types : [$types] as $type) {
            foreach ($this->required[(string) $type] ?? [] as $requirement) {
                $met = array_filter($requirement, fn (string $path): bool => ! self::isEmpty(self::read($node, explode('.', $path), $graph, 0)));

                if ($met === [] && ! in_array($requirement, $missing, true)) {
                    $missing[] = $requirement;
                }
            }
        }

        return $missing;
    }

    /**
     * The value at the path, read through references and into lists, where
     * the first item that has it answers; null when nothing does.
     *
     * @param  list<string>  $path
     * @param  array<string, array<string, mixed>>  $graph
     */
    private static function read(mixed $value, array $path, array $graph, int $hops): mixed
    {
        if (is_array($value) && array_keys($value) === ['@id'] && is_string($value['@id']) && isset($graph[$value['@id']]) && $hops < self::HOPS) {
            return self::read($graph[$value['@id']], $path, $graph, $hops + 1);
        }

        if ($path === []) {
            return $value;
        }

        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $item) {
                $found = self::read($item, $path, $graph, $hops);

                if (! self::isEmpty($found)) {
                    return $found;
                }
            }

            return null;
        }

        $key = array_shift($path);

        return is_array($value) && array_key_exists($key, $value) ? self::read($value[$key], $path, $graph, $hops) : null;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
