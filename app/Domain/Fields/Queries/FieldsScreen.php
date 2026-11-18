<?php

/**
 * Site › Types › {type} › Fields (I-3320): the type's own fields in order,
 * the fields Teme's features give it, its removed fields and those that
 * can't be read, and the field types the side sheet offers. It reads; the
 * Operations change.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Queries;

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Stored\FieldCatalogue;
use App\Domain\Fields\Stored\FieldDefinition;
use App\Domain\Fields\Stored\FieldValues;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Queries\TypesScreen;
use App\Domain\Types\TypeAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

final readonly class FieldsScreen
{
    /**
     * Where each feature's fields sit in the editor, in the editor's order
     * of places, and within one place in TypesScreen::FEATURES' order.
     *
     * @var array<string, string>
     */
    public const array WHERE = [
        'excerpt' => 'Under the title',
        'featured_image' => 'Under the title',
        'seo' => 'Under the article',
        'template' => 'In the side panel',
        'sticky' => 'On the list, not in the editor',
    ];

    /** Where a field of code's with no feature sits: in Details. */
    public const string IN_DETAILS = 'In Details';

    /**
     * The type's Fields page, or null when the site has no such type.
     *
     * @return array{type: array{key: string, singular: string, plural: string, noun: string, typeUrl: string}, locked: bool, fields: list<array<string, mixed>>, fromTeme: list<array{name: string, handles: string, fieldType: string, where: string, feature: ?string}>, removed: list<array<string, mixed>>, unreadable: list<array{key: string, handle: string, sentence: string}>, catalogue: list<array<string, mixed>>}|null
     */
    public function page(string $type): ?array
    {
        $types = app(ContentTypeRegistry::class);

        if (! $types->has($type)) {
            return null;
        }

        $contentType = $types->get($type);
        $registry = app(FieldRegistry::class);
        $live = $registry->stored($type);
        $removed = $registry->removed($type);
        $holding = FieldValues::counts($type, [
            ...array_map(fn (Field $field): string => $field->key, $live),
            ...array_map(fn (FieldDefinition $field): string => $field->key, $removed),
        ]);
        $positions = DB::table(StoredFields::TABLE)->where('content_type', $type)->pluck('position', 'key')->all();

        return [
            'type' => [
                'key' => $contentType->key,
                'singular' => $contentType->singular,
                'plural' => $contentType->plural,
                'noun' => mb_strtolower($contentType->singular),
                'typeUrl' => route('types.show', ['key' => $contentType->key], false),
            ],
            'locked' => TypeAccess::locked(),
            'fields' => array_map(fn (Field $field): array => $this->row($field, $holding[$field->key] ?? 0, (int) ($positions[$field->key] ?? 0)), $live),
            'fromTeme' => $this->fromTeme($type),
            'removed' => array_map(fn (FieldDefinition $field): array => $this->removedRow($field, $holding[$field->key] ?? 0), $removed),
            'unreadable' => array_map(fn (array $row): array => [
                'key' => $row['key'],
                'handle' => $row['handle'],
                'sentence' => __('The saved field “:handle” can’t be used: :reason', ['handle' => $row['handle'], 'reason' => $row['reason']]),
            ], $registry->unreadable($type)),
            'catalogue' => FieldCatalogue::forBuilder(),
        ];
    }

    /**
     * The names of the features that give the type fields, in the Fields
     * page's order, for the type's own page.
     *
     * @return list<string>
     */
    public function features(string $type): array
    {
        $names = [];

        foreach ($this->fromTeme($type) as $row) {
            if ($row['feature'] !== null) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }

    /**
     * The type's stored live fields' labels, in their order.
     *
     * @return list<string>
     */
    public function labels(string $type): array
    {
        return array_map(fn (Field $field): string => $field->label, app(FieldRegistry::class)->stored($type));
    }

    /**
     * The label of the type's stored field with this key, live or removed,
     * for a toast; the key itself when there is none.
     */
    public function label(string $type, string $key): string
    {
        $registry = app(FieldRegistry::class);

        foreach ([...$registry->stored($type), ...$registry->removed($type)] as $field) {
            if ($field->key === $key) {
                return $field->label;
            }
        }

        return $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Field $field, int $holding, int $position): array
    {
        $definition = $this->definition($field);

        return [
            'key' => $field->key,
            'handle' => $field->handle,
            'label' => $field->label,
            'help' => $field->description,
            'fieldType' => $field->type->name(),
            'settings' => $definition['settings'],
            'required' => $field->required,
            'search' => $field->search,
            'filterable' => $field->filterable,
            'show' => $field->show->value,
            'summary' => $this->summary($field->type->name(), $definition['settings']),
            'holding' => $holding,
            'position' => $position,
        ];
    }

    /**
     * A live field's settings as its definition stores them: a Choice's
     * choices without the None the registry adds, a date's kind.
     *
     * @return array{settings: array<string, mixed>}
     */
    private function definition(Field $field): array
    {
        return match ($field->type->name()) {
            'select' => ['settings' => ['choices' => array_map(
                fn (int|string $value, string $label): array => ['value' => (string) $value, 'label' => $label],
                array_keys(array_diff_key($field->options, ['' => true])),
                array_values(array_diff_key($field->options, ['' => true])),
            )]],
            'datetime' => ['settings' => ['dateOnly' => ($field->type->edit($field)['settings']['dateOnly'] ?? false) === true]],
            default => ['settings' => []],
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function summary(string $fieldType, array $settings): string
    {
        if ($fieldType === 'select') {
            $count = is_array($settings['choices'] ?? null) ? count($settings['choices']) : 0;

            return $count === 1 ? __('1 choice') : __(':n choices', ['n' => (string) $count]);
        }

        if ($fieldType === 'datetime' && ($settings['dateOnly'] ?? false) === true) {
            return __('Date only');
        }

        if ($fieldType === 'media') {
            return __('Images');
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function removedRow(FieldDefinition $field, int $holding): array
    {
        $by = $field->removedBy === null ? null : DB::table('users')->where('id', $field->removedBy)->value('name');

        return [
            'key' => $field->key,
            'handle' => $field->handle,
            'label' => $field->label,
            'fieldType' => $field->fieldType,
            'holding' => $holding,
            'removedAt' => $field->removedAt === null ? '' : $this->day($field->removedAt),
            'removedBy' => is_string($by) ? $by : null,
            'clearing' => null,
        ];
    }

    /**
     * One row per feature the type supports that gives it fields, then one
     * per field of code's with no feature, each saying where it sits.
     *
     * @return list<array{name: string, handles: string, fieldType: string, where: string, feature: ?string}>
     */
    private function fromTeme(string $type): array
    {
        $byFeature = [];
        $loose = [];

        foreach (app(FieldRegistry::class)->forDeclaration(app(ContentTypeRegistry::class)->get($type)) as $field) {
            if ($field->feature === null) {
                $loose[] = $field;
            } else {
                $byFeature[$field->feature][] = $field;
            }
        }

        $features = [...array_keys(self::WHERE), ...array_diff(array_keys($byFeature), array_keys(self::WHERE))];
        $rows = [];

        foreach ($features as $feature) {
            $fields = $byFeature[$feature] ?? [];

            if ($fields === []) {
                continue;
            }

            $named = TypesScreen::FEATURES[$feature] ?? null;
            $rows[] = [
                'name' => $named['field'] ?? $feature,
                'handles' => $this->handles($fields),
                'fieldType' => count($fields) === 1 ? FieldCatalogue::label($fields[0]->type->name()) : __('Several'),
                'where' => self::WHERE[$feature] ?? self::IN_DETAILS,
                'feature' => $named['label'] ?? $feature,
            ];
        }

        foreach ($loose as $field) {
            $rows[] = [
                'name' => $field->label,
                'handles' => $field->handle,
                'fieldType' => FieldCatalogue::label($field->type->name()),
                'where' => self::IN_DETAILS,
                'feature' => null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<Field>  $fields
     */
    private function handles(array $fields): string
    {
        $handles = array_map(fn (Field $field): string => $field->handle, $fields);

        if (count($handles) <= 2) {
            return implode(', ', $handles);
        }

        return __(':first, :second and :n more', ['first' => $handles[0], 'second' => $handles[1], 'n' => (string) (count($handles) - 2)]);
    }

    /**
     * A date as TypesScreen words when a type was made: the day, on the site's clock.
     */
    private function day(CarbonImmutable $at): string
    {
        $zone = app(SettingsReader::class)->string('general', 'timezone');

        return $at->timezone($zone)->format('j F Y');
    }
}
