<?php

/**
 * One per-person preference (I-110): its key, type, default and wording,
 * declared in the same spirit as a setting, with the settings' type names
 * where they overlap. A `select` offers value → label; a `number` has a
 * range; a `list` is an ordered list of strings, each one its callback
 * allows.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Preferences;

use Closure;

final readonly class PreferenceDefinition
{
    /** The types a preference may have. */
    public const array TYPES = ['boolean', 'select', 'number', 'list'];

    /**
     * @param  array<int|string, string>  $options  value => label, for a select
     * @param  (Closure(): list<string>)|null  $allowed  the values a list may hold
     */
    public function __construct(
        public string $key,
        public string $type,
        public mixed $default,
        public string $label,
        public string $description = '',
        public array $options = [],
        public ?Closure $allowed = null,
        public ?int $min = null,
        public ?int $max = null,
        public ?int $maxItems = null,
    ) {}

    /**
     * A value as the declared type, turning what a form sends — '0' and '1',
     * a number as text — into it. Anything else is left as it is, for
     * allows() to refuse.
     */
    public function normalise(mixed $value): mixed
    {
        return match ($this->type) {
            'boolean' => match (true) {
                $value === '1', $value === 1, $value === true => true,
                $value === '0', $value === 0, $value === false => false,
                default => $value,
            },
            'number' => self::integer($value),
            'select' => is_int($this->default) ? self::integer($value) : $value,
            'list' => is_array($value)
                ? array_values(array_filter(array_map(fn (mixed $item): mixed => is_scalar($item) ? (string) $item : $item, $value), fn (mixed $item): bool => $item !== ''))
                : $value,
            default => $value,
        };
    }

    /**
     * Whether a value, already normalised, is one this preference may hold.
     */
    public function allows(mixed $value): bool
    {
        return match ($this->type) {
            'boolean' => is_bool($value),
            'number' => is_int($value)
                && ($this->min === null || $value >= $this->min)
                && ($this->max === null || $value <= $this->max),
            'select' => (is_int($value) || is_string($value)) && in_array($value, array_keys($this->options), true),
            'list' => $this->allowsList($value),
            default => false,
        };
    }

    /**
     * A stored list without the items its callback no longer allows, in its
     * own order, so one removed entry does not empty the whole list. Anything
     * that is not a list is returned as it is, for allows() to refuse.
     */
    public function keepAllowedItems(mixed $value): mixed
    {
        if ($this->type !== 'list' || ! is_array($value) || ! array_is_list($value)) {
            return $value;
        }

        $allowed = $this->allowed === null ? [] : ($this->allowed)();

        return array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && in_array($item, $allowed, true)));
    }

    /**
     * The declaration in the settings screen's field shape, so the preferences
     * screen draws it with the same components.
     *
     * @return array{key: string, type: string, label: string, description: string, options: array<int|string, string>, min: int|null, max: int|null, maxLength: null, required: false, example: null}
     */
    public function toField(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'label' => $this->label,
            'description' => $this->description,
            'options' => $this->options,
            'min' => $this->min,
            'max' => $this->max,
            'maxLength' => null,
            'required' => false,
            'example' => null,
        ];
    }

    private function allowsList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== count(array_unique($value, SORT_REGULAR))) {
            return false;
        }

        if ($this->maxItems !== null && count($value) > $this->maxItems) {
            return false;
        }

        $allowed = $this->allowed === null ? [] : ($this->allowed)();

        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private static function integer(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value;
    }
}
