<?php

declare(strict_types=1);

namespace Tests\Fixtures\Site;

use ReflectionObject;
use ReflectionProperty;

/**
 * Walks a page model as a template could, through every public property,
 * list and array, and names each value that is a framework object: a
 * template is given plain PHP values and Teme's own view models only (I-3346).
 */
final class PlainValues
{
    /** The namespaces no value a template reads may come from. */
    private const array REFUSED = ['Carbon\\', 'Illuminate\\', 'Symfony\\'];

    /**
     * Each path to a framework object, with its class, such as
     * "ArticleView::$fieldViews[release_date]->value: Carbon\CarbonImmutable".
     *
     * @return list<string>
     */
    public static function offenders(object $model): array
    {
        $found = [];
        $seen = [];

        self::walkObject($model, (new ReflectionObject($model))->getShortName(), true, $found, $seen);

        return $found;
    }

    /**
     * @param  list<string>  $found
     * @param  array<int, true>  $seen
     */
    private static function walk(mixed $value, string $path, array &$found, array &$seen): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::walk($item, "{$path}[{$key}]", $found, $seen);
            }

            return;
        }

        if (is_object($value)) {
            self::walkObject($value, $path, false, $found, $seen);
        }
    }

    /**
     * @param  list<string>  $found
     * @param  array<int, true>  $seen
     */
    private static function walkObject(object $value, string $path, bool $root, array &$found, array &$seen): void
    {
        // A class of Teme's own that extends a framework one is refused too.
        foreach ([$value::class, ...class_parents($value)] as $class) {
            foreach (self::REFUSED as $namespace) {
                if (str_starts_with($class, $namespace)) {
                    $found[] = $path.': '.$value::class;

                    return;
                }
            }
        }

        if (isset($seen[spl_object_id($value)])) {
            return;
        }

        $seen[spl_object_id($value)] = true;

        foreach ((new ReflectionObject($value))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($value)) {
                self::walk($property->getValue($value), $root ? "{$path}::\${$property->getName()}" : "{$path}->{$property->getName()}", $found, $seen);
            }
        }
    }
}
