<?php

/**
 * Checks what a plugin declares for search engines (docs/guides/developers/plugins/seo.md):
 * its editor checks, its Search and social section, its sitemaps and the
 * schema.org terms its graph nodes use; the capabilities each needs; and, as
 * the plugin starts, that its class hands over what it declares. Every
 * refusal is one plain sentence.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Types\ContentTypeRegistry;
use Teme\Contracts\Editor\HasEditorChecks;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Sitemaps\HasSitemaps;
use Teme\Contracts\Sitemaps\PluginSitemap;
use Throwable;

final class SeoValidator
{
    /** An editor check's or a sitemap's id. */
    public const string ID = '/^[a-z][a-z0-9-]{1,23}$/D';

    /** A Search and social field's key. */
    public const string FIELD_KEY = '/^[a-z][a-z0-9_]{0,31}$/D';

    /** The most checks one plugin may declare. */
    public const int MOST_CHECKS = 20;

    /** The most fields one Search and social section may hold. */
    public const int MOST_FIELDS = 8;

    /** The most sitemaps one plugin may declare. */
    public const int MOST_SITEMAPS = 4;

    /** The kinds of field a section may hold, and the longest text each takes by default. */
    public const array FIELD_TYPES = ['text' => 200, 'textarea' => 2_000, 'number' => null, 'boolean' => null, 'select' => null, 'url' => 2_000];

    /** Where a section's tab may sit among core's. */
    public const array POSITIONS = ['after-search', 'after-social', 'last'];

    /** A schema.org type a plugin may declare. */
    public const string SCHEMA_TYPE = '/^[A-Z][A-Za-z0-9]{1,63}$/D';

    /** A schema.org property a plugin may declare. */
    public const string SCHEMA_PROPERTY = '/^[a-z][A-Za-z0-9]{1,63}$/D';

    /**
     * Why the declarations can't be used, or nothing when they can. The
     * capabilities and content types are checked only when the
     * capabilities are given, as when a manifest is read.
     *
     * @param  array<array-key, mixed>  $contributes
     * @param  list<string>|null  $capabilities
     *
     * @return list<string>
     */
    public function errors(array $contributes, ?array $capabilities): array
    {
        $errors = [];
        $checkTypes = $capabilities !== null;

        if (array_key_exists('editorChecks', $contributes)) {
            $errors = [...$errors, ...$this->checkErrors($contributes['editorChecks'], $checkTypes)];

            if ($capabilities !== null && $contributes['editorChecks'] !== [] && ! in_array(Capability::ContentRead->value, $capabilities, true)) {
                $errors[] = __('It adds editor checks, but the plugin doesn’t ask for :capability.', ['capability' => Capability::ContentRead->value]);
            }
        }

        if (array_key_exists('searchSection', $contributes)) {
            $errors = [...$errors, ...$this->sectionErrors($contributes['searchSection'], $checkTypes)];
        }

        if (array_key_exists('sitemaps', $contributes)) {
            $errors = [...$errors, ...$this->sitemapErrors($contributes['sitemaps'])];

            if ($capabilities !== null && $contributes['sitemaps'] !== [] && ! in_array(Capability::Sitemaps->value, $capabilities, true)) {
                $errors[] = __('It adds sitemaps, but the plugin doesn’t ask for :capability.', ['capability' => Capability::Sitemaps->value]);
            }
        }

        if (array_key_exists('structuredData', $contributes)) {
            $errors = [...$errors, ...$this->termErrors($contributes['structuredData'])];

            if ($capabilities !== null && ! in_array(Capability::PagesHead->value, $capabilities, true)) {
                $errors[] = __('It declares structured data terms, but the plugin doesn’t ask for :capability.', ['capability' => Capability::PagesHead->value]);
            }
        }

        return $errors;
    }

    /**
     * What a starting plugin's class must hand over for what it declares:
     * its checks and its sitemaps.
     *
     * @param  array<array-key, mixed>  $contributes
     *
     * @return list<string>
     */
    public function startErrors(array $contributes, object $plugin): array
    {
        $errors = [];
        $checks = $contributes['editorChecks'] ?? [];

        if (is_array($checks) && $checks !== [] && ! $plugin instanceof HasEditorChecks) {
            $errors[] = __('It declares editor checks but doesn’t implement :interface.', ['interface' => HasEditorChecks::class]);
        } elseif ((! is_array($checks) || $checks === []) && $plugin instanceof HasEditorChecks) {
            $errors[] = __('It runs editor checks but declares none.');
        }

        $sitemaps = $contributes['sitemaps'] ?? [];
        $declared = array_values(array_filter(array_column(array_filter(is_array($sitemaps) ? $sitemaps : [], is_array(...)), 'id'), is_string(...)));

        if ($declared === []) {
            return $plugin instanceof HasSitemaps ? [...$errors, __('It hands over sitemaps but declares none.')] : $errors;
        }

        if (! $plugin instanceof HasSitemaps) {
            return [...$errors, __('It declares sitemaps but doesn’t implement :interface.', ['interface' => HasSitemaps::class])];
        }

        try {
            $given = $plugin->sitemaps();
        } catch (Throwable) {
            return [...$errors, __('Its sitemaps couldn’t be read.')];
        }

        foreach ($declared as $id) {
            if (! (($given[$id] ?? null) instanceof PluginSitemap)) {
                $errors[] = __('It declares the sitemap “:id” but has no PluginSitemap for it.', ['id' => $id]);
            }
        }

        foreach (array_keys($given) as $id) {
            if (! in_array($id, $declared, true)) {
                $errors[] = __('It has a sitemap “:id”, which isn’t declared.', ['id' => (string) $id]);
            }
        }

        return $errors;
    }

    /**
     * Whether a value for an address field is on the site's own host, which
     * is never stored: the site makes its own addresses at render, so they
     * follow it when it moves (rule 9, docs/guides/developers/plugins/seo.md).
     *
     * @param  array<array-key, mixed>  $field
     */
    public function onThisSite(array $field, mixed $value): bool
    {
        if (($field['type'] ?? null) !== 'url' || ! is_string($value) || $value === '') {
            return false;
        }

        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) && mb_strtolower($host) === mb_strtolower((string) parse_url(url('/'), PHP_URL_HOST));
    }

    /**
     * Whether a value fits a section's field as declared: its kind, length,
     * option or bounds. Null always fits: it clears the value.
     *
     * @param  array<array-key, mixed>  $field
     */
    public function fits(array $field, mixed $value): bool
    {
        $type = is_string($field['type'] ?? null) ? $field['type'] : '';
        $most = is_int($field['maxLength'] ?? null) ? $field['maxLength'] : (self::FIELD_TYPES[$type] ?? 0);

        return $value === null || match ($type) {
            'text', 'textarea' => is_string($value) && mb_strlen($value) <= $most,
            'url' => is_string($value) && mb_strlen($value) <= $most && ($value === '' || (filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', $value) === 1)),
            'select' => (is_string($value) || is_int($value)) && is_array($field['options'] ?? null) && array_key_exists($value, $field['options']),
            'boolean' => is_bool($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value)
                && (! is_numeric($field['min'] ?? null) || $value >= $field['min'])
                && (! is_numeric($field['max'] ?? null) || $value <= $field['max']),
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    private function checkErrors(mixed $entries, bool $checkTypes): array
    {
        if (! is_array($entries) || ! array_is_list($entries)) {
            return [__('Its editor checks must be a list.')];
        }

        $errors = count($entries) > self::MOST_CHECKS ? [__('A plugin can add at most 20 editor checks.')] : [];
        $ids = [];

        foreach ($entries as $entry) {
            $entry = is_array($entry) ? $entry : [];
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';

            foreach (array_keys($entry) as $key) {
                if (! in_array($key, ['id', 'label', 'types'], true)) {
                    $errors[] = __('An editor check can’t have :key.', ['key' => (string) $key]);
                }
            }

            if (preg_match(self::ID, $id) !== 1) {
                $errors[] = __('An editor check’s id must be 2 to 24 lower-case letters, digits and hyphens, starting with a letter.');
            } elseif (in_array($id, $ids, true)) {
                $errors[] = __('Two editor checks can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;
            $label = is_string($entry['label'] ?? null) ? trim($entry['label']) : '';

            if ($label === '' || mb_strlen($label) > 60) {
                $errors[] = __('An editor check’s label must be 1 to 60 characters.');
            }

            $errors = [...$errors, ...$this->typeErrors($entry['types'] ?? null, __('An editor check'), $checkTypes)];
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function sectionErrors(mixed $section, bool $checkTypes): array
    {
        if (! is_array($section) || array_is_list($section)) {
            return [__('Its Search and social section must be an object.')];
        }

        $errors = [];

        foreach (array_keys($section) as $key) {
            if (! in_array($key, ['label', 'description', 'types', 'position', 'fields'], true)) {
                $errors[] = __('A Search and social section can’t have :key.', ['key' => (string) $key]);
            }
        }

        $label = is_string($section['label'] ?? null) ? trim($section['label']) : '';

        if ($label === '' || mb_strlen($label) > 24) {
            $errors[] = __('A Search and social section’s label must be 1 to 24 characters.');
        }

        if (array_key_exists('description', $section) && (! is_string($section['description']) || mb_strlen($section['description']) > 160)) {
            $errors[] = __('A Search and social section’s description must be text of at most 160 characters.');
        }

        if (array_key_exists('position', $section) && ! in_array($section['position'], self::POSITIONS, true)) {
            $errors[] = __('A Search and social section’s position must be after-search, after-social or last.');
        }

        $errors = [...$errors, ...$this->typeErrors($section['types'] ?? null, __('A Search and social section'), $checkTypes)];
        $fields = $section['fields'] ?? null;

        if (! is_array($fields) || $fields === [] || ! array_is_list($fields)) {
            return [...$errors, __('A Search and social section needs a list of fields.')];
        }

        if (count($fields) > self::MOST_FIELDS) {
            $errors[] = __('A Search and social section can hold at most 8 fields.');
        }

        $keys = [];

        foreach ($fields as $field) {
            $field = is_array($field) ? $field : [];
            $key = is_string($field['key'] ?? null) ? $field['key'] : '';

            if (preg_match(self::FIELD_KEY, $key) !== 1) {
                $errors[] = __('A Search and social field’s key must be lower-case letters, digits and underscores, starting with a letter.');
            } elseif (in_array($key, $keys, true)) {
                $errors[] = __('Two Search and social fields can’t share the key :key.', ['key' => $key]);
            }

            $keys[] = $key;
            $errors = [...$errors, ...$this->fieldErrors($field)];
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $field
     *
     * @return list<string>
     */
    private function fieldErrors(array $field): array
    {
        $errors = [];
        $type = is_string($field['type'] ?? null) ? $field['type'] : '';

        foreach (array_keys($field) as $key) {
            if (! in_array($key, ['key', 'type', 'label', 'description', 'maxLength', 'options', 'min', 'max', 'summary'], true)) {
                $errors[] = __('A Search and social field can’t have :key.', ['key' => (string) $key]);
            }
        }

        if (! array_key_exists($type, self::FIELD_TYPES)) {
            return [...$errors, __('A Search and social field’s type must be text, textarea, number, boolean, select or url.')];
        }

        $label = is_string($field['label'] ?? null) ? trim($field['label']) : '';

        if ($label === '' || mb_strlen($label) > 40) {
            $errors[] = __('A Search and social field’s label must be 1 to 40 characters.');
        }

        if (array_key_exists('description', $field) && (! is_string($field['description']) || mb_strlen($field['description']) > 200)) {
            $errors[] = __('A Search and social field’s description must be text of at most 200 characters.');
        }

        if (array_key_exists('maxLength', $field) && (! in_array($type, ['text', 'textarea'], true) || ! is_int($field['maxLength']) || $field['maxLength'] < 1 || $field['maxLength'] > 2_000)) {
            $errors[] = __('Only a text or textarea field takes a maxLength, from 1 to 2,000.');
        }

        if ($type === 'select') {
            $options = $field['options'] ?? null;

            if (! is_array($options) || $options === [] || array_is_list($options) || count(array_filter($options, fn (mixed $label): bool => is_string($label) && trim($label) !== '')) !== count($options)) {
                $errors[] = __('A select field needs its options, each a value and a label.');
            }
        } elseif (array_key_exists('options', $field)) {
            $errors[] = __('Only a select field takes options.');
        }

        foreach (['min', 'max'] as $bound) {
            if (array_key_exists($bound, $field) && ($type !== 'number' || ! is_int($field[$bound]) && ! is_float($field[$bound]))) {
                $errors[] = __('Only a number field takes a :bound, and it must be a number.', ['bound' => $bound]);
            }
        }

        if (array_key_exists('summary', $field) && ! is_bool($field['summary'])) {
            $errors[] = __('A Search and social field’s summary must be true or false.');
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function sitemapErrors(mixed $entries): array
    {
        if (! is_array($entries) || ! array_is_list($entries)) {
            return [__('Its sitemaps must be a list.')];
        }

        $errors = count($entries) > self::MOST_SITEMAPS ? [__('A plugin can add at most 4 sitemaps.')] : [];
        $ids = [];

        foreach ($entries as $entry) {
            $entry = is_array($entry) ? $entry : [];
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';

            foreach (array_keys($entry) as $key) {
                if (! in_array($key, ['id', 'label'], true)) {
                    $errors[] = __('A sitemap can’t have :key.', ['key' => (string) $key]);
                }
            }

            if (preg_match(self::ID, $id) !== 1) {
                $errors[] = __('A sitemap’s id must be 2 to 24 lower-case letters, digits and hyphens, starting with a letter.');
            } elseif (in_array($id, $ids, true)) {
                $errors[] = __('Two sitemaps can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;
            $label = is_string($entry['label'] ?? null) ? trim($entry['label']) : '';

            if ($label === '' || mb_strlen($label) > 40) {
                $errors[] = __('A sitemap’s label must be 1 to 40 characters.');
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function termErrors(mixed $terms): array
    {
        if (! is_array($terms) || array_is_list($terms) && $terms !== []) {
            return [__('Its structured data terms must be an object of types and properties.')];
        }

        $errors = [];

        foreach (array_keys($terms) as $key) {
            if (! in_array($key, ['types', 'properties'], true)) {
                $errors[] = __('Its structured data terms can’t have :key.', ['key' => (string) $key]);
            }
        }

        foreach (['types' => [self::SCHEMA_TYPE, 40], 'properties' => [self::SCHEMA_PROPERTY, 80]] as $key => [$pattern, $most]) {
            $names = $terms[$key] ?? [];

            if (! is_array($names) || ! array_is_list($names) || count($names) > $most) {
                $errors[] = __('Its structured data :key must be a list of at most :most names.', ['key' => $key, 'most' => $most]);

                continue;
            }

            foreach ($names as $name) {
                if (! is_string($name) || preg_match($pattern, $name) !== 1) {
                    $errors[] = $key === 'types'
                        ? __('A structured data type is a schema.org type’s name, such as FAQPage.')
                        : __('A structured data property is a schema.org property’s name, such as acceptedAnswer.');
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function typeErrors(mixed $types, string $what, bool $checkTypes): array
    {
        if (! is_array($types) || $types === [] || ! array_is_list($types)) {
            return [__(':what must name at least one content type.', ['what' => $what])];
        }

        $errors = [];

        foreach ($types as $type) {
            if (! is_string($type) || preg_match(ContributionsValidator::TYPE_KEY, $type) !== 1) {
                $errors[] = __(':what’s type must be a lower-case content type key.', ['what' => $what]);
            } elseif ($checkTypes && ! app(ContentTypeRegistry::class)->has($type)) {
                $errors[] = __(':what names :type, which isn’t a content type here.', ['what' => $what, 'type' => $type]);
            }
        }

        return $errors;
    }
}
