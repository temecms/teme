<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

use App\Domain\Plugins\ManifestText;
use Illuminate\Validation\ValidationException;

/**
 * The plugins' tabs in Search and social (docs/guides/developers/plugins/seo.md):
 * which a content type shows, and their values as an item's fields keep
 * them, one object under each plugin's key, typed and checked as declared.
 * A value of a plugin that doesn't show its tab now travels with the item
 * untouched, as a removed field's does.
 */
final readonly class PluginSearchFields
{
    /** What every plugin's key in an item's fields starts with. */
    public const string PREFIX = 'plugin_';

    public function __construct(
        private SeoPlugins $plugins,
        private SeoValidator $validator,
    ) {}

    /**
     * The tabs a content type shows, by the key their values are kept under.
     *
     * @return array<string, array{plugin: SeoPlugin, section: array{label: string, description: string, types: list<string>, position: string, fields: list<array<string, mixed>>}}>
     */
    public function for(string $type): array
    {
        $sections = [];

        foreach ($this->plugins->all() as $plugin) {
            $section = SeoDeclarations::section($plugin->manifest);

            if ($section !== null && in_array($type, $section['types'], true)) {
                $sections[SeoDeclarations::fieldsKey($plugin->code)] = ['plugin' => $plugin, 'section' => $section];
            }
        }

        return $sections;
    }

    /**
     * Whether a key of an item's fields is a plugin's.
     */
    public static function isPluginKey(string $key): bool
    {
        return str_starts_with($key, self::PREFIX);
    }

    /**
     * The plugins' values as the item keeps them after this save: those sent
     * for a tab the type shows, each checked against its field; and every
     * other plugin's from what the item holds, whatever was sent for it.
     *
     * @param  array<string, mixed>  $sent  the plugins' keys sent with the save
     * @param  array<string, mixed>  $current  the fields the item holds now
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws ValidationException for a value its field doesn't take, or a key it doesn't declare
     */
    public function validate(string $type, array $sent, array $current): array
    {
        if ($sent === [] && $current === []) {
            return [];
        }

        $sections = $this->for($type);
        $stored = [];
        $errors = [];

        // Kept as they are: a plugin's values whose tab isn't shown, and any not sent this time.
        foreach ($current as $key => $value) {
            if (self::isPluginKey($key) && (! isset($sections[$key]) || ! array_key_exists($key, $sent)) && is_array($value)) {
                $stored[$key] = $value;
            }
        }

        foreach ($sent as $key => $values) {
            if (! isset($sections[$key]) || $values === null) {
                continue;
            }

            if (! is_array($values) || ($values !== [] && array_is_list($values))) {
                $errors["fields.{$key}"] = __('These values must be an object of the tab’s fields.');

                continue;
            }

            $fields = array_column($sections[$key]['section']['fields'], null, 'key');
            $kept = [];

            foreach ($values as $name => $value) {
                $field = $fields[$name] ?? null;

                if ($field === null) {
                    $errors["fields.{$key}.{$name}"] = __('“:tab” has no field :name.', ['tab' => $sections[$key]['section']['label'], 'name' => (string) $name]);
                } elseif (! $this->validator->fits($field, $value)) {
                    $errors["fields.{$key}.{$name}"] = __('“:label” doesn’t take that value.', ['label' => (string) $field['label']]);
                } elseif ($value !== null && $value !== '') {
                    $kept[(string) $name] = is_string($value) ? trim($value) : $value;
                }
            }

            if ($kept !== []) {
                $stored[$key] = $kept;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $stored;
    }

    /**
     * One plugin's values on an item, by key; none when it keeps none.
     *
     * @param  array<array-key, mixed>  $fields  the item's fields as stored
     *
     * @return array<string, mixed>
     */
    public static function valuesOf(string $code, array $fields): array
    {
        $values = $fields[SeoDeclarations::fieldsKey($code)] ?? [];

        return is_array($values) && ! array_is_list($values) ? $values : [];
    }

    /**
     * The tabs as the editor's Search and social box draws them: each
     * plugin's key, name and code, its tab's words, place and fields.
     *
     * @return list<array{key: string, code: string, plugin: string, label: string, description: string, position: string, fields: list<array<string, mixed>>}>
     */
    public function tabsFor(string $type): array
    {
        $tabs = [];
        $locale = app()->getLocale();

        foreach ($this->for($type) as $key => ['plugin' => $plugin, 'section' => $section]) {
            $words = ManifestText::for($plugin->code);
            $text = fn (mixed $english): string => is_string($english) ? $words->text($english, $locale) : '';
            $summaryKeys = array_values(array_filter($section['fields'], fn (array $field): bool => ($field['summary'] ?? false) === true));

            $tabs[] = [
                'key' => $key,
                'code' => $plugin->code,
                'plugin' => $plugin->label,
                'label' => $text($section['label']),
                'description' => $text($section['description']),
                'position' => $section['position'],
                'fields' => array_map(fn (array $field): array => [
                    'key' => (string) $field['key'],
                    'type' => (string) $field['type'],
                    'label' => $text($field['label']),
                    'description' => $text($field['description'] ?? null),
                    'maxLength' => in_array($field['type'], ['text', 'textarea', 'url'], true) ? (is_int($field['maxLength'] ?? null) ? $field['maxLength'] : SeoValidator::FIELD_TYPES[$field['type']]) : null,
                    'options' => is_array($field['options'] ?? null) ? array_map(fn (int|string $value, mixed $label): array => ['value' => (string) $value, 'label' => $text($label)], array_keys($field['options']), $field['options']) : [],
                    'min' => $field['min'] ?? null,
                    'max' => $field['max'] ?? null,
                    'summary' => $summaryKeys === [] ? $field === $section['fields'][0] : ($field['summary'] ?? false) === true,
                ], $section['fields']),
            ];
        }

        return $tabs;
    }
}
