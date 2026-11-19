<?php

/**
 * One field a site made for one of its types, as the table stores it and
 * teme-types.json carries it (docs/studies/content-and-fields.md §5.3): its
 * key, which values are stored under, its handle, what the editor shows,
 * and its settings. Its position is the table's; a file's order is its own.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Stored;

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldPlace;
use App\Domain\Fields\FieldShow;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class FieldDefinition
{
    /** The names a file entry may have, in the order it is written. */
    public const array KNOWN = ['key', 'type', 'handle', 'label', 'help', 'fieldType', 'settings', 'required', 'search', 'filterable', 'show'];

    /**
     * @param  array<string, mixed>  $settings
     * @param  FieldShow|null  $show  null when the entry named no place Teme knows; FieldRules refuses it
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $handle,
        public string $label,
        public string $help,
        public string $fieldType,
        public array $settings,
        public bool $required,
        public bool $search,
        public bool $filterable,
        public ?FieldShow $show,
        public int $position = 0,
        public ?CarbonImmutable $removedAt = null,
        public ?int $removedBy = null,
    ) {}

    /**
     * Reads a file entry, or a request's field, checking its shape only. A
     * missing key reads as '', for a field not made yet.
     *
     * @param  array<mixed>  $raw
     *
     * @throws InvalidArgumentException listing every problem, one sentence each
     */
    public static function fromArray(array $raw): self
    {
        $problems = [];

        if ($raw !== [] && array_is_list($raw)) {
            throw new InvalidArgumentException('A field must be an object of named values.');
        }

        foreach (['type', 'handle', 'label', 'fieldType'] as $name) {
            if (! array_key_exists($name, $raw)) {
                $problems[] = "[{$name}] is missing.";
            } elseif (! is_string($raw[$name])) {
                $problems[] = "[{$name}] must be text.";
            }
        }

        foreach (['key', 'help', 'show'] as $name) {
            if (array_key_exists($name, $raw) && ! is_string($raw[$name])) {
                $problems[] = "[{$name}] must be text.";
            }
        }

        foreach (['required', 'search', 'filterable'] as $name) {
            if (array_key_exists($name, $raw) && ! is_bool($raw[$name])) {
                $problems[] = "[{$name}] must be true or false.";
            }
        }

        if (array_key_exists('settings', $raw) && (! is_array($raw['settings']) || ($raw['settings'] !== [] && array_is_list($raw['settings'])))) {
            $problems[] = '[settings] must be an object of named values.';
        }

        foreach (array_keys($raw) as $name) {
            if (! in_array($name, self::KNOWN, true)) {
                $problems[] = "[{$name}] isn’t part of a version 1 field.";
            }
        }

        if ($problems !== []) {
            throw new InvalidArgumentException(implode(' ', $problems));
        }

        /** @var array{key?: string, type: string, handle: string, label: string, help?: string, fieldType: string, settings?: array<string, mixed>, required?: bool, search?: bool, filterable?: bool, show?: string} $raw */
        return new self(
            key: $raw['key'] ?? '',
            type: $raw['type'],
            handle: $raw['handle'],
            label: $raw['label'],
            help: $raw['help'] ?? '',
            fieldType: $raw['fieldType'],
            settings: $raw['settings'] ?? [],
            required: $raw['required'] ?? false,
            search: $raw['search'] ?? false,
            filterable: $raw['filterable'] ?? false,
            show: FieldShow::tryFrom($raw['show'] ?? FieldShow::After->value),
        );
    }

    /**
     * Reads a row of the table: its columns, and the definition its JSONB holds.
     *
     * @param  array<string, mixed>|object  $row
     *
     * @throws InvalidArgumentException when the row doesn't read
     */
    public static function fromRow(array|object $row): self
    {
        $row = (array) $row;
        $definition = is_string($row['definition'] ?? null)
            ? json_decode($row['definition'], true, flags: JSON_THROW_ON_ERROR)
            : ($row['definition'] ?? null);

        if (! is_array($definition)) {
            throw new InvalidArgumentException('A field must be an object of named values.');
        }

        $read = self::fromArray(['key' => (string) $row['key'], 'type' => (string) $row['content_type'], 'handle' => (string) $row['handle'], ...$definition]);
        $removedAt = $row['removed_at'] ?? null;

        return $read->with([
            'position' => (int) $row['position'],
            'removedAt' => $removedAt === null ? null : CarbonImmutable::parse($removedAt),
            'removedBy' => ($row['removed_by'] ?? null) === null ? null : (int) $row['removed_by'],
        ]);
    }

    /**
     * The file's entry: every name in KNOWN's order, without the position.
     *
     * @return array{key: string, type: string, handle: string, label: string, help: string, fieldType: string, settings: array<string, mixed>, required: bool, search: bool, filterable: bool, show: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'handle' => $this->handle,
            'label' => $this->label,
            'help' => $this->help,
            'fieldType' => $this->fieldType,
            'settings' => $this->settings,
            'required' => $this->required,
            'search' => $this->search,
            'filterable' => $this->filterable,
            'show' => ($this->show ?? FieldShow::After)->value,
        ];
    }

    /**
     * The field as the registry holds it, drawn in Details. A choice always
     * offers None first, so an item never touched shows none.
     *
     * @throws InvalidArgumentException for a field type the builder doesn't offer
     */
    public function toField(): Field
    {
        $options = [];

        if ($this->fieldType === 'select') {
            $options[''] = 'None';

            foreach ($this->choices() as $choice) {
                $options[$choice['value']] = $choice['label'];
            }
        }

        return new Field(
            key: $this->key,
            handle: $this->handle,
            label: $this->label,
            description: $this->help,
            type: FieldCatalogue::make($this->fieldType, $this->settings),
            contentTypes: [$this->type],
            since: '',
            required: $this->required,
            options: $options,
            place: FieldPlace::Details,
            search: $this->search,
            filterable: $this->filterable,
            show: $this->show ?? FieldShow::After,
            stored: true,
        );
    }

    /**
     * A Choice's choices, as far as they read; none for any other field.
     *
     * @return list<array{value: string, label: string}>
     */
    public function choices(): array
    {
        $choices = [];

        foreach (is_array($this->settings['choices'] ?? null) ? $this->settings['choices'] : [] as $choice) {
            if (is_array($choice) && is_string($choice['value'] ?? null) && is_string($choice['label'] ?? null)) {
                $choices[] = ['value' => $choice['value'], 'label' => $choice['label']];
            }
        }

        return $choices;
    }

    public function withKey(string $key): self
    {
        return $this->with(['key' => $key]);
    }

    public function withPosition(int $position): self
    {
        return $this->with(['position' => $position]);
    }

    /**
     * The field removed at this moment by this person, or live again with both null.
     */
    public function withRemoval(?CarbonImmutable $at, ?int $by): self
    {
        return $this->with(['removedAt' => $at, 'removedBy' => $by]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
