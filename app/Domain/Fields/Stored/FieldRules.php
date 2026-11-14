<?php

declare(strict_types=1);

namespace App\Domain\Fields\Stored;

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\StoredTypes;
use Illuminate\Support\Facades\DB;

/**
 * What a site's field must meet before it is stored: its label, help and
 * handle, a field type the builder offers that never changes in place,
 * settings that keep stored values meaning what they meant, and only the
 * boxes its field type offers (docs/studies/content-and-fields.md §5.3).
 */
final class FieldRules
{
    private const string HANDLE = '/^[a-z][a-z0-9_]{0,63}$/';

    private const string CHOICE = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /**
     * The first problem per input name, in the order label, help, handle,
     * fieldType, settings, required, search, filterable, show, type. $current
     * is the stored field $next replaces, null for a new one; $holding how
     * many items hold a value for it.
     *
     * @return array<string, string>
     */
    public static function problems(FieldDefinition $next, ?FieldDefinition $current, ?int $holding): array
    {
        return self::check($next, $current, $holding ?? 0, database: true);
    }

    /**
     * The problems a file's entry has on its own, before the import: every
     * rule but those the stored rows answer (another field's handle, the
     * type) and those a stored field answers (a change in place).
     *
     * @return array<string, string>
     */
    public static function fileProblems(FieldDefinition $next): array
    {
        return self::check($next, null, 0, database: false);
    }

    /**
     * @return array<string, string>
     */
    private static function check(FieldDefinition $next, ?FieldDefinition $current, int $holding, bool $database): array
    {
        $problems = [];
        [$plural, $noun] = self::names($next->type);

        $label = mb_strlen(trim($next->label));

        if ($label < 1) {
            $problems['label'] = __('Give the field a label.');
        } elseif ($label > 80) {
            $problems['label'] = __('A label is at most 80 characters.');
        }

        if (mb_strlen($next->help) > 300) {
            $problems['help'] = __('Help text is at most 300 characters.');
        }

        $handle = self::handleProblem($next, $plural, $database);

        if ($handle !== null) {
            $problems['handle'] = $handle;
        }

        if (trim($next->fieldType) === '') {
            $problems['fieldType'] = __('Choose a field type.');
        } elseif (! FieldCatalogue::has($next->fieldType)) {
            $problems['fieldType'] = __('That isn’t a field type Teme has.');
        } elseif ($current !== null && $current->fieldType !== $next->fieldType) {
            $problems['fieldType'] = __('Can’t change: stored values would be read as another kind. To change it, add a new field and remove this one.');
        }

        if (! isset($problems['fieldType'])) {
            $problems = [...$problems, ...self::settingsProblems($next, $current, $holding, $noun)];
        }

        if ($next->required && ! FieldCatalogue::offers($next->fieldType, 'required') && FieldCatalogue::has($next->fieldType)) {
            $problems['required'] = __('A Yes or no field can’t be required.');
        }

        if ($next->search && ! FieldCatalogue::offers($next->fieldType, 'search')) {
            $problems['search'] = __('Only text, long text and choice fields can be searched.');
        }

        if ($next->filterable && ! FieldCatalogue::offers($next->fieldType, 'filterable')) {
            $problems['filterable'] = __('This kind of field can’t be filtered.');
        }

        if ($next->show === null) {
            $problems['show'] = __('Choose where it shows on the site.');
        }

        if ($database && ! app(ContentTypeRegistry::class)->has($next->type) && ! DB::table(StoredTypes::TABLE)->where('key', $next->type)->exists()) {
            $problems['type'] = __('There is no type :type on this site.', ['type' => $next->type]);
        }

        return $problems;
    }

    /**
     * The handle's first problem: its form, one of code's handles on any
     * type, another live field's on the type, or a removed field's on the
     * type while it holds values. Rows are read from the table's columns, a
     * row that doesn't read included, so a change under the types' lock sees
     * what others committed.
     */
    private static function handleProblem(FieldDefinition $next, string $plural, bool $database): ?string
    {
        if (preg_match(self::HANDLE, $next->handle) !== 1) {
            return __('A handle is lower case letters, digits and underscores, starting with a letter, at most 64 characters.');
        }

        $code = array_map(fn (Field $field): string => $field->handle, app(FieldRegistry::class)->all());

        if (in_array($next->handle, $code, true)) {
            return __(':handle is one of Teme’s own fields, so a field of yours can’t use it.', ['handle' => $next->handle]);
        }

        if (! $database) {
            return null;
        }

        foreach (app(StoredFields::class)->handles($next->type) as $other) {
            if ($other['key'] === $next->key || $other['handle'] !== $next->handle) {
                continue;
            }

            if (! $other['removed']) {
                return __(':Plural already have a field called :handle.', ['plural' => $plural, 'handle' => $next->handle]);
            }

            $holding = FieldValues::counts($next->type, [$other['key']])[$other['key']] ?? 0;

            if ($holding > 0) {
                return __('A removed field called :handle still holds values on :n :plural. Delete them, or choose another handle.', ['handle' => $next->handle, 'n' => (string) $holding, 'plural' => mb_strtolower($plural)]);
            }
        }

        return null;
    }

    /**
     * Before an import writes any field: for each file entry, by its index,
     * the handle it would share, once imported, with a field of its type the
     * file doesn't name: a live one, or a removed one still holding values.
     *
     * @param  list<FieldDefinition>  $entries
     *
     * @return array<int, string>
     */
    public static function importHandleProblems(array $entries): array
    {
        $named = array_map(fn (FieldDefinition $entry): string => $entry->key, $entries);
        $rows = [];
        $problems = [];

        foreach ($entries as $i => $entry) {
            $rows[$entry->type] ??= app(StoredFields::class)->handles($entry->type);

            foreach ($rows[$entry->type] as $other) {
                if (in_array($other['key'], $named, true) || $other['handle'] !== $entry->handle) {
                    continue;
                }

                $plural = self::names($entry->type)[0];

                if (! $other['removed']) {
                    $problems[$i] = __(':Plural already have a field called :handle.', ['plural' => $plural, 'handle' => $entry->handle]);

                    break;
                }

                $holding = FieldValues::counts($entry->type, [$other['key']])[$other['key']] ?? 0;

                if ($holding > 0) {
                    $problems[$i] = __('A removed field called :handle still holds values on :n :plural. Delete them, or choose another handle.', ['handle' => $entry->handle, 'n' => (string) $holding, 'plural' => mb_strtolower($plural)]);

                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * What the settings of a field type the builder offers must say: only
     * the names it takes; a Choice's list, whose stored values never change;
     * a date's kind, which can't change while items hold values.
     *
     * @return array<string, string>
     */
    private static function settingsProblems(FieldDefinition $next, ?FieldDefinition $current, int $holding, string $noun): array
    {
        $allowed = FieldCatalogue::settings($next->fieldType);

        foreach (array_keys($next->settings) as $name) {
            if (! in_array($name, $allowed, true)) {
                return ['settings' => __('[settings.:name] isn’t part of a version 1 field.', ['name' => (string) $name])];
            }
        }

        if ($next->fieldType === 'select') {
            $problem = self::choicesProblem($next, $current);

            return $problem === null ? [] : ['settings.choices' => $problem];
        }

        if ($next->fieldType === 'datetime') {
            $dateOnly = $next->settings['dateOnly'] ?? false;

            if (! is_bool($dateOnly)) {
                return ['settings.dateOnly' => __('[settings.dateOnly] must be true or false.')];
            }

            if ($current !== null && $holding > 0 && $dateOnly !== (($current->settings['dateOnly'] ?? false) === true)) {
                return ['settings.dateOnly' => __('Can’t change once a :noun holds a value.', ['noun' => $noun])];
            }
        }

        return [];
    }

    /**
     * A Choice's first problem: one to a hundred choices, each stored as a
     * short lower-case value of its own with a label, and no stored value
     * renamed.
     */
    private static function choicesProblem(FieldDefinition $next, ?FieldDefinition $current): ?string
    {
        $choices = $next->settings['choices'] ?? null;

        if (! is_array($choices) || ! array_is_list($choices) || $choices === []) {
            return __('Give a choice at least one option.');
        }

        if (count($choices) > 100) {
            return __('At most 100 choices.');
        }

        $seen = [];

        foreach ($choices as $choice) {
            $value = is_array($choice) ? ($choice['value'] ?? null) : null;

            if (! is_string($value) || preg_match(self::CHOICE, $value) !== 1) {
                return __('A choice is stored as lower case letters, digits, hyphens and underscores, starting with a letter or digit.');
            }

            if (isset($seen[$value])) {
                return __('Two choices are stored as :value.', ['value' => $value]);
            }

            $seen[$value] = true;
            $label = $choice['label'] ?? null;

            if (! is_string($label) || mb_strlen(trim($label)) < 1 || mb_strlen(trim($label)) > 80) {
                return __('Give each choice a label.');
            }
        }

        if ($current !== null) {
            $before = array_column($current->choices(), 'label', 'value');
            $after = array_column($choices, 'label', 'value');
            $takenOff = array_diff_key($before, $after);

            // A new value under the label of one taken off is that one renamed.
            foreach (array_diff_key($after, $before) as $label) {
                if (in_array($label, $takenOff, true)) {
                    return __('A choice’s stored value can’t change once it is made; relabel it instead.');
                }
            }
        }

        return null;
    }

    /**
     * Why $next can't take $current's place: a field stays on the type it
     * was made on, whose items hold its values. Null when it doesn't move.
     */
    public static function moveProblem(FieldDefinition $current, FieldDefinition $next): ?string
    {
        if ($current->type === $next->type) {
            return null;
        }

        return __('A field can’t move to another type once it is made: :handle belongs to :plural.', [
            'handle' => $current->handle,
            'plural' => mb_strtolower(self::names($current->type)[0]),
        ]);
    }

    /**
     * The type's plural and its singular in lower case, as the editor's noun;
     * its key for both when there is no such type.
     *
     * @return array{0: string, 1: string}
     */
    private static function names(string $type): array
    {
        $types = app(ContentTypeRegistry::class);

        if ($types->has($type)) {
            return [$types->get($type)->plural, mb_strtolower($types->get($type)->singular)];
        }

        $stored = app(StoredTypes::class)->findLeniently($type);

        return $stored === null ? [$type, $type] : [$stored->plural, mb_strtolower($stored->singular)];
    }
}
