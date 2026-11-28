<?php

/**
 * The rules of a plugin record's form (docs/plans/plugin-sockets-2.md, A2,
 * A8), from its declaration: each field's type, bounds and options, the
 * pickers' values against core's current lists, a repeater's items one by
 * one. Hidden fields are dropped first, never validated, never sent on.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use App\Domain\Contents\Queries\ItemChoices;
use App\Domain\Regions\RegionDeclarations;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Users\Actors;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

final class AreaForms
{
    /** A colour value. */
    public const string COLOR = '/^#[0-9a-f]{6}$/D';

    /** The longest text and textarea when the field names no maxLength. */
    public const int MOST_TEXT = 1_000;

    public const int MOST_TEXTAREA = 10_000;

    /**
     * The values the plugin is given: only fields that show, each checked
     * and in its type's shape. Throws a ValidationException keyed
     * `values.{key}` (`values.{key}.{n}.{sub}` in a repeater) when any is refused.
     *
     * @param  list<array<string, mixed>>  $fields  as DeclaredArea::fields() gives them
     * @param  array<array-key, mixed>  $values  as sent
     * @param  string|null  $personId  the person saving: the items they may open are the only ones accepted
     * @param  string  $plugin  the plugin's name, as a refusal names it
     * @param  array<string, mixed>  $stored  the values the record stores now, by key: an item it already holds is kept, not refused, when this person can't read it
     * @param  array<string, list<array{value: string, label: string}>|null>  $offered  a select whose choices the plugin lists, by key: what it offers now, or null when it couldn't answer, which keeps the saved value
     *
     * @return array<string, mixed>
     */
    public static function validated(array $fields, array $values, ?string $personId = null, string $plugin = '', array $stored = [], array $offered = []): array
    {
        $shown = VisibleWhen::visibleValues($fields, $values);
        $errors = [];
        $kept = [];

        foreach ($fields as $field) {
            $key = (string) $field['key'];

            if (! array_key_exists($key, $shown)) {
                continue;
            }

            if (($field['choices'] ?? null) === 'plugin' && array_key_exists($key, $offered)) {
                $kept[$key] = self::pluginChoice($field, $shown[$key], $offered[$key], $stored[$key] ?? null, "values.{$key}", $errors);

                continue;
            }

            $kept[$key] = self::one($field, $shown[$key], "values.{$key}", $errors, $personId, $plugin, is_array($stored[$key] ?? null) ? $stored[$key] : []);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $kept;
    }

    /**
     * The stored values a form shows: declared keys only, each of its
     * field's kind, and whether anything was left out as the wrong kind.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<array-key, mixed>  $given
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public static function kept(array $fields, array $given): array
    {
        $kept = [];
        $refused = false;

        foreach ($fields as $field) {
            $key = (string) $field['key'];

            if (! array_key_exists($key, $given)) {
                continue;
            }

            $value = $given[$key];

            if (! self::ofKind($field, $value)) {
                $refused = true;

                continue;
            }

            if ($field['type'] === 'repeater' && is_array($value)) {
                $items = [];

                foreach ($value as $item) {
                    [$one, $wrong] = self::kept(is_array($field['fields']) ? array_values($field['fields']) : [], is_array($item) ? $item : []);
                    $items[] = $one;
                    $refused = $refused || $wrong || ! is_array($item);
                }

                $value = $items;
            }

            $kept[$key] = $value;
        }

        foreach (array_keys($given) as $key) {
            if (! in_array($key, array_column($fields, 'key'), true)) {
                $refused = true;
            }
        }

        return [$kept, $refused];
    }

    /**
     * A field's value when nothing is stored: its declared default, or its type's empty.
     *
     * @param  array<string, mixed>  $field
     */
    public static function empty(array $field): mixed
    {
        if ($field['default'] !== null && self::ofKind($field, $field['default'])) {
            return $field['default'];
        }

        return match ($field['type']) {
            'number' => null,
            'boolean' => false,
            'multiselect', 'types', 'terms', 'items', 'repeater' => [],
            'select' => array_key_first(is_array($field['options']) ? $field['options'] : []) ?? '',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private static function ofKind(array $field, mixed $value): bool
    {
        return match ($field['type']) {
            'number' => $value === null || is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'multiselect', 'types', 'terms', 'items' => is_array($value) && array_is_list($value) && array_filter($value, fn (mixed $one): bool => ! is_string($one)) === [],
            'repeater' => is_array($value) && array_is_list($value),
            default => is_string($value),
        };
    }

    /**
     * One shown field's value, checked; its problems added under $path.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, array<mixed>|string>  $errors  each refused value's sentence, by its path
     * @param  list<mixed>  $held  the items the record stores now: kept, not refused, when this person can't read them
     */
    private static function one(array $field, mixed $value, string $path, array &$errors, ?string $personId = null, string $plugin = '', array $held = []): mixed
    {
        $label = (string) $field['label'];
        $empty = $value === null || $value === '' || $value === [];

        // An empty repeater still holds its least number of items.
        if ($empty && $field['type'] === 'repeater' && ! ($field['required'] === true)) {
            return self::items($field, [], $path, $errors, $personId, $plugin);
        }

        if ($empty) {
            if ($field['required'] === true) {
                $errors[$path] = __(':field is required.', ['field' => $label]);
            }

            return self::empty([...$field, 'default' => null]);
        }

        $type = (string) $field['type'];
        /** @var array<string, string> $options */
        $options = is_array($field['options']) ? $field['options'] : [];
        $min = is_int($field['min']) ? $field['min'] : null;
        $max = is_int($field['max']) ? $field['max'] : null;
        $maxLength = is_int($field['maxLength']) ? $field['maxLength'] : null;

        switch ($type) {
            case 'number':
                if (is_string($value) && is_numeric($value)) {
                    $value += 0;
                }

                if (! is_int($value) && ! is_float($value)) {
                    $errors[$path] = __(':field must be a number.', ['field' => $label]);

                    return null;
                }

                if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
                    $errors[$path] = __(':field must be between :min and :max.', ['field' => $label, 'min' => $min ?? '…', 'max' => $max ?? '…']);
                }

                return $value;

            case 'boolean':
                return in_array($value, [true, 1, '1', 'true', 'on'], true);

            case 'select':
                if (! is_string($value) || ! array_key_exists($value, $options)) {
                    $errors[$path] = __('Choose one of :field’s options.', ['field' => $label]);
                }

                return is_string($value) ? $value : '';

            case 'multiselect':
            case 'types':
            case 'terms':
                $list = is_array($value) ? array_values(array_unique(array_map(fn (mixed $one): string => is_scalar($one) ? (string) $one : '', $value))) : null;

                // A term stored as its slug is checked against the taxonomy's slugs now; a missing one is refused, named by the plugin.
                if ($type === 'terms' && ($field['storeAs'] ?? 'id') === 'slug' && $list !== null) {
                    $missing = array_values(array_diff($list, self::termSlugs(is_string($field['taxonomy']) ? $field['taxonomy'] : '', $list)));

                    if ($missing !== []) {
                        $taxonomy = is_string($field['taxonomy']) ? $field['taxonomy'] : '';
                        $singular = mb_strtolower(app(TaxonomyRegistry::class)->get($taxonomy)->singular);
                        $errors[$path] = __('“:slug” isn’t a :singular on this site now, so :plugin can’t save it. Remove it, or choose the :singular again.', ['slug' => $missing[0], 'singular' => $singular, 'plugin' => $plugin !== '' ? $plugin : $label]);
                    }

                    return $list;
                }

                $allowed = match ($type) {
                    'multiselect' => array_map(strval(...), array_keys($options)),
                    'types' => array_map(fn (ContentType $type): string => $type->key, app(ContentTypeRegistry::class)->all()),
                    default => $list === null ? [] : self::terms(is_string($field['taxonomy']) ? $field['taxonomy'] : '', $list),
                };

                if ($list === null || array_diff($list, $allowed) !== []) {
                    $errors[$path] = __('Choose from :field’s list.', ['field' => $label]);
                }

                return $list ?? [];

            case 'items':
                $list = is_array($value) ? array_values(array_unique(array_map(fn (mixed $one): string => is_scalar($one) ? (string) $one : '', $value))) : null;

                // An item the record already stores, which this person can't read now, is kept as it is, not refused.
                $alreadyStored = array_map(strval(...), $held);

                if ($list === null || array_diff($list, self::readableItems($list, $personId), $alreadyStored) !== []) {
                    $errors[$path] = __('Choose from :field’s list.', ['field' => $label]);
                }

                return $list ?? [];

            case 'region':
                if (! is_string($value) || ! array_key_exists($value, app(RegionDeclarations::class)->forActive())) {
                    $errors[$path] = __('Choose one of the theme’s regions for :field.', ['field' => $label]);
                }

                return is_string($value) ? $value : '';

            case 'datetime':
                try {
                    $time = is_string($value) ? CarbonImmutable::parse($value, app(SettingsReader::class)->string('general', 'timezone')) : null;
                } catch (Throwable) {
                    $time = null;
                }

                if ($time === null) {
                    $errors[$path] = __(':field must be a date and time.', ['field' => $label]);

                    return '';
                }

                return $time->toIso8601String();

            case 'repeater':
                return self::items($field, $value, $path, $errors, $personId, $plugin, $held);
        }

        if (! is_string($value)) {
            $errors[$path] = __(':field must be text.', ['field' => $label]);

            return '';
        }

        $most = match ($type) {
            'code' => AreaValidator::MOST_CODE,
            'textarea' => $maxLength ?? self::MOST_TEXTAREA,
            default => $maxLength ?? self::MOST_TEXT,
        };

        $rule = match ($type) {
            'email' => 'email',
            'url' => 'url:http,https',
            'color' => 'regex:'.self::COLOR,
            default => null,
        };

        if (mb_strlen($value) > $most) {
            $errors[$path] = __(':field must be at most :most characters.', ['field' => $label, 'most' => $most]);
        } elseif ($rule !== null && Validator::make(['value' => $value], ['value' => $rule])->fails()) {
            $errors[$path] = __(':field isn’t valid.', ['field' => $label]);
        }

        return $type === 'color' ? strtolower($value) : $value;
    }

    /**
     * A select whose choices the plugin lists: the value must be one it offers
     * now. When the plugin couldn't answer, the saved value stays as it is.
     *
     * @param  array<string, mixed>  $field
     * @param  list<array{value: string, label: string}>|null  $offered
     * @param  array<string, string>  $errors
     */
    private static function pluginChoice(array $field, mixed $value, ?array $offered, mixed $saved, string $path, array &$errors): mixed
    {
        $label = (string) $field['label'];

        if ($offered === null) {
            return $saved;
        }

        if ($value === null || $value === '') {
            if (($field['required'] ?? false) === true) {
                $errors[$path] = __(':field is required.', ['field' => $label]);
            }

            return null;
        }

        foreach ($offered as $choice) {
            if ($value === $choice['value']) {
                return $value;
            }
        }

        $errors[$path] = __('Choose from :field’s list.', ['field' => $label]);

        return null;
    }

    /**
     * A repeater's items, each its shown sub-fields checked.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, array<mixed>|string>  $errors  each refused value's sentence, by its path
     * @param  list<mixed>  $held  the items the record stores now, whose picked items are kept, not refused, when this person can't read them
     *
     * @return list<array<string, mixed>>
     */
    private static function items(array $field, mixed $value, string $path, array &$errors, ?string $personId = null, string $plugin = '', array $held = []): array
    {
        $label = (string) $field['label'];
        $minItems = is_int($field['minItems']) ? $field['minItems'] : 0;
        $maxItems = is_int($field['maxItems']) ? $field['maxItems'] : AreaValidator::DEFAULT_MOST_ITEMS;
        /** @var list<array<string, mixed>> $subs */
        $subs = is_array($field['fields']) ? array_values($field['fields']) : [];

        if (! is_array($value) || ! array_is_list($value)) {
            $errors[$path] = __(':field must be a list.', ['field' => $label]);

            return [];
        }

        if (count($value) < $minItems || count($value) > $maxItems) {
            $errors[$path] = __(':field holds :min to :max items.', ['field' => $label, 'min' => $minItems, 'max' => $maxItems]);
        }

        $items = [];

        foreach (array_slice($value, 0, $maxItems) as $index => $item) {
            $item = is_array($item) ? $item : [];
            $shown = VisibleWhen::visibleValues($subs, $item);
            $kept = [];

            foreach ($subs as $sub) {
                $key = (string) $sub['key'];

                if (array_key_exists($key, $shown)) {
                    // Items reorder, so what any stored item holds for this sub-field is kept.
                    $stored = array_merge(...array_map(fn (mixed $was): array => is_array($was) && is_array($was[$key] ?? null) ? array_values($was[$key]) : [], $held));
                    $kept[$key] = self::one($sub, $shown[$key], "{$path}.{$index}.{$key}", $errors, $personId, $plugin, $stored);
                }
            }

            $items[] = $kept;
        }

        return $items;
    }

    /**
     * Which of these IDs are terms of the taxonomy.
     *
     * @param  list<string>  $ids
     *
     * @return list<string>
     */
    private static function terms(string $taxonomy, array $ids): array
    {
        $ulids = array_values(array_filter($ids, fn (string $id): bool => preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/i', $id) === 1));

        if ($ulids === []) {
            return [];
        }

        return DB::table('terms')->where('taxonomy', $taxonomy)->whereIn('id', $ulids)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
    }

    /**
     * Which of these content IDs the person may open: a live item, or a draft
     * they may update, of a post or a page. Nothing else is accepted.
     *
     * @param  list<string>  $ids
     *
     * @return list<string>
     */
    public static function readableItems(array $ids, ?string $personId): array
    {
        $person = $personId === null ? null : app(Actors::class)->find($personId);

        if ($person === null || $ids === []) {
            return [];
        }

        return ItemChoices::readableIds($ids, $person);
    }

    /**
     * Which of these slugs are terms of the taxonomy now.
     *
     * @param  list<string>  $slugs
     *
     * @return list<string>
     */
    public static function termSlugs(string $taxonomy, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return DB::table('terms')->where('taxonomy', $taxonomy)->whereIn('slug', $slugs)->pluck('slug')->map(fn (mixed $slug): string => (string) $slug)->all();
    }
}
