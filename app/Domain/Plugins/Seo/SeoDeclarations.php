<?php

/**
 * What a plugin's manifest declares for search engines, read each time:
 * its editor checks, its Search and social section, its sitemaps and its
 * schema.org terms. An entry that isn't well formed is left out; the
 * validator refused it first.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

use App\Domain\Plugins\Images\ImageServiceDeclaration;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestText;
use App\Domain\Plugins\PluginKeys;
use App\Domain\Plugins\PluginWords;
use App\Domain\Types\ContentTypeRegistry;

final class SeoDeclarations
{
    /**
     * The key a plugin's Search and social values are kept under in an
     * item's fields: never a field's own key, which is a ULID.
     */
    public static function fieldsKey(string $code): string
    {
        return PluginKeys::stem($code);
    }

    /**
     * The editor checks, in declared order.
     *
     * @return list<array{id: string, label: string, types: list<string>}>
     */
    public static function checks(Manifest $manifest): array
    {
        $checks = [];

        foreach (self::list($manifest->contributes()['editorChecks'] ?? null) as $entry) {
            if (is_string($entry['id'] ?? null) && is_string($entry['label'] ?? null)) {
                $checks[] = ['id' => $entry['id'], 'label' => $entry['label'], 'types' => self::strings($entry['types'] ?? null)];
            }
        }

        return $checks;
    }

    /**
     * The Search and social section, or null when there is none.
     *
     * @return array{label: string, description: string, types: list<string>, position: string, fields: list<array<string, mixed>>}|null
     */
    public static function section(Manifest $manifest): ?array
    {
        $section = $manifest->contributes()['searchSection'] ?? null;

        if (! is_array($section) || ! is_string($section['label'] ?? null)) {
            return null;
        }

        $fields = array_values(array_filter(self::list($section['fields'] ?? null), fn (array $field): bool => is_string($field['key'] ?? null) && is_string($field['type'] ?? null) && is_string($field['label'] ?? null)));

        return [
            'label' => $section['label'],
            'description' => is_string($section['description'] ?? null) ? $section['description'] : '',
            'types' => self::strings($section['types'] ?? null),
            'position' => is_string($section['position'] ?? null) && in_array($section['position'], SeoValidator::POSITIONS, true) ? $section['position'] : 'last',
            'fields' => $fields,
        ];
    }

    /**
     * The sitemaps, in declared order.
     *
     * @return list<array{id: string, label: string}>
     */
    public static function sitemaps(Manifest $manifest): array
    {
        $sitemaps = [];

        foreach (self::list($manifest->contributes()['sitemaps'] ?? null) as $entry) {
            if (is_string($entry['id'] ?? null) && is_string($entry['label'] ?? null)) {
                $sitemaps[] = ['id' => $entry['id'], 'label' => $entry['label']];
            }
        }

        return $sitemaps;
    }

    /**
     * The schema.org terms its graph nodes may use beyond the graph's list.
     *
     * @return array{types: list<string>, properties: list<string>}
     */
    public static function terms(Manifest $manifest): array
    {
        $terms = $manifest->contributes()['structuredData'] ?? null;
        $terms = is_array($terms) ? $terms : [];

        return [
            'types' => array_values(array_filter(self::strings($terms['types'] ?? null), fn (string $name): bool => preg_match(SeoValidator::SCHEMA_TYPE, $name) === 1)),
            'properties' => array_values(array_filter(self::strings($terms['properties'] ?? null), fn (string $name): bool => preg_match(SeoValidator::SCHEMA_PROPERTY, $name) === 1)),
        ];
    }

    /**
     * The consent's lines for the checks and the section the manifest
     * declares, labels in the admin's language and types by their plural
     * names, and the image service's (ImageServiceDeclaration). The checks
     * are left out while content.read isn't agreed.
     *
     * @param  list<string>|null  $agreed  the capabilities agreed, or null for what it asks
     *
     * @return list<string>
     */
    public static function consentWords(Manifest $manifest, ?array $agreed = null): array
    {
        $words = ManifestText::for($manifest->code());
        $locale = app()->getLocale();
        $lines = [];
        $checks = self::checks($manifest);

        if ($checks !== [] && ($agreed === null || in_array('content.read', $agreed, true))) {
            $lines[] = PluginWords::editorChecks(
                array_map(fn (array $check): string => $words->text($check['label'], $locale), $checks),
                self::typeNames(array_values(array_unique(array_merge(...array_column($checks, 'types'))))),
            );
        }

        $section = self::section($manifest);

        if ($section !== null) {
            $lines[] = PluginWords::searchSection($words->text($section['label'], $locale), self::typeNames($section['types']));
        }

        $sitemaps = self::sitemaps($manifest);

        if ($sitemaps !== [] && ($agreed === null || in_array('sitemaps', $agreed, true))) {
            $lines[] = PluginWords::sitemaps(array_map(fn (array $sitemap): string => $words->text($sitemap['label'], $locale), $sitemaps));
        }

        // Shown with these, where the Plugins screen and teme:plugins:enable read them.
        return [...$lines, ...ImageServiceDeclaration::consentWords($manifest, $agreed)];
    }

    /**
     * Content types by their plural names, as the admin shows them.
     *
     * @param  list<string>  $types
     *
     * @return list<string>
     */
    private static function typeNames(array $types): array
    {
        $registry = app(ContentTypeRegistry::class);

        return array_map(fn (string $type): string => $registry->has($type) ? __($registry->get($type)->plural) : $type, $types);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $entries): array
    {
        return is_array($entries) ? array_values(array_filter($entries, is_array(...))) : [];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
