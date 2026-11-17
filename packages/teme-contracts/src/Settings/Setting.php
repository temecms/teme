<?php

declare(strict_types=1);

namespace Teme\Contracts\Settings;

use InvalidArgumentException;

/**
 * One setting's declaration: its type, its default, its constraints and the
 * documentation that the reference is generated from. Immutable — every
 * modifier returns a new declaration.
 *
 * @since 0.1.0
 */
final class Setting
{
    /**
     * The fieldsets a design setting can be grouped under: the whole site,
     * the latest posts wherever they are served, a single item's content,
     * and archive listings.
     *
     * @since 0.1.0
     */
    public const array FIELDSETS = ['site', 'latest', 'content', 'archive'];

    /**
     * The days an hours setting holds, Monday first.
     *
     * @since 0.1.0
     */
    public const array DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * @param  array<string, string>  $options  value => label for a select; for pairs, the two columns' labels, keyed name and value
     * @param  array<string, scalar|list<scalar>>  $visibleWhen
     * @param  string|null  $taxonomy  the taxonomy a term setting's terms come from
     * @param  bool  $multiple  whether a term setting holds a list of terms
     */
    private function __construct(
        public readonly string $key,
        public readonly SettingType $type,
        public readonly string $label,
        public readonly string $description,
        public readonly string $since,
        public readonly mixed $default,
        public readonly array $options = [],
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly ?int $maxLength = null,
        public readonly bool $required = false,
        public readonly ?string $example = null,
        public readonly ?string $env = null,
        public readonly ?string $fieldset = null,
        public readonly array $visibleWhen = [],
        public readonly ?string $unit = null,
        public readonly ?string $taxonomy = null,
        public readonly bool $multiple = false,
        /** @since 1.0.0 */
        public readonly ?string $section = null,
        /**
         * A repeater's fields, one item's worth.
         *
         * @var list<Setting>
         *
         * @since 1.0.0
         */
        public readonly array $fields = [],
        /** A repeater's field whose value titles a collapsed item. @since 1.0.0 */
        public readonly ?string $itemTitle = null,
        /** A code setting whose inline scripts wait for an administrator's approval. @since 1.0.0 */
        public readonly bool $scripts = false,
    ) {}

    /** @since 0.1.0 */
    public static function text(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Text, $label, $description, $since, '');
    }

    /** @since 0.1.0 */
    public static function textarea(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Textarea, $label, $description, $since, '');
    }

    /** @since 0.1.0 */
    public static function number(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Number, $label, $description, $since, 0);
    }

    /** @since 0.1.0 */
    public static function boolean(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Boolean, $label, $description, $since, false);
    }

    /**
     * @param  array<string, string>  $options  value => label; the first is the default
     *
     * @since 0.1.0
     */
    public static function select(string $key, string $label, string $description, string $since, array $options): self
    {
        if ($options === []) {
            throw new InvalidArgumentException("Select setting [{$key}] needs at least one option.");
        }

        return new self($key, SettingType::Select, $label, $description, $since, array_key_first($options), options: $options);
    }

    /**
     * @param  array<string, string>  $options  value => label; the default selects none
     *
     * @since 0.1.0
     */
    public static function multiselect(string $key, string $label, string $description, string $since, array $options): self
    {
        if ($options === []) {
            throw new InvalidArgumentException("Multiselect setting [{$key}] needs at least one option.");
        }

        return new self($key, SettingType::MultiSelect, $label, $description, $since, [], options: $options);
    }

    /** @since 0.1.0 */
    public static function url(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Url, $label, $description, $since, '');
    }

    /** @since 0.1.0 */
    public static function email(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Email, $label, $description, $since, '');
    }

    /** @since 0.1.0 */
    public static function secret(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Secret, $label, $description, $since, null);
    }

    /** @since 0.1.0 */
    public static function timezone(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Timezone, $label, $description, $since, 'UTC');
    }

    /**
     * A colour, stored as `#rrggbb` in lower case, or `''` when unset.
     *
     * @since 0.1.0
     */
    public static function color(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Color, $label, $description, $since, '');
    }

    /**
     * An image from the media library, stored as its ID, or `''` when none
     * is chosen.
     *
     * @since 0.1.0
     */
    public static function media(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Media, $label, $description, $since, '');
    }

    /**
     * Rows of a name and a value, stored as a list of
     * `{"name": "…", "value": "…"}`, empty by default. The two labels name
     * the columns on the form and in its messages; max() bounds the rows
     * (50 when unset), maxLength() each value (2,000 when unset), and a
     * name is at most 100 characters.
     *
     * @since 0.1.0
     */
    public static function pairs(string $key, string $label, string $description, string $since, string $nameLabel = 'Name', string $valueLabel = 'Value'): self
    {
        return new self($key, SettingType::Pairs, $label, $description, $since, [], options: ['name' => $nameLabel, 'value' => $valueLabel]);
    }

    /**
     * Addresses on other sites, one a line, stored as a list, empty by
     * default: each http or https, never one on the site's own host, which
     * the site makes itself. max() bounds how many (20 when unset).
     *
     * @since 0.1.0
     */
    public static function urls(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Urls, $label, $description, $since, []);
    }

    /**
     * Someone with an account, stored as their ID in digits, or `''` when
     * nobody is chosen.
     *
     * @since 0.1.0
     */
    public static function person(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Person, $label, $description, $since, '');
    }

    /**
     * A week of opening hours, saying nothing by default. Sent as days keyed
     * by DAYS, each `closed` or with `hours`, one or two openings of `opens`
     * and `closes` in 24-hour `HH:MM`; stored as the days that say
     * something, Monday first, each `{day, hours, closed}`.
     *
     * @since 0.1.0
     */
    public static function hours(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Hours, $label, $description, $since, []);
    }

    /**
     * Terms of one taxonomy, stored as their slugs: one slug, or `''` when
     * none is chosen; with $multiple, a list of slugs, empty by default.
     * Each must be a term the taxonomy has when the setting is saved.
     *
     * @since 1.0.0
     */
    public static function term(string $key, string $label, string $description, string $since, string $taxonomy, bool $multiple = false): self
    {
        if ($taxonomy === '') {
            throw new InvalidArgumentException("Term setting [{$key}] needs a taxonomy.");
        }

        return new self($key, SettingType::Term, $label, $description, $since, $multiple ? [] : '', taxonomy: $taxonomy, multiple: $multiple);
    }

    /**
     * One of the site's content types with public addresses, stored as its
     * key, or `''` when none is chosen.
     *
     * @since 1.0.0
     */
    public static function contentType(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::ContentType, $label, $description, $since, '');
    }

    /**
     * Markup, such as an ad network's snippet, in a plain monospace box,
     * never run or drawn on the settings page; at most 65,535 characters.
     *
     * @since 1.0.0
     */
    public static function code(string $key, string $label, string $description, string $since): self
    {
        return new self($key, SettingType::Code, $label, $description, $since, '');
    }

    /**
     * A list of items of one shape, stored as a JSON list of objects keyed
     * by field, empty by default. Its fields are text, textarea, number,
     * boolean, select, multiselect, email, color and code settings; min()
     * and max() bound the items (20 at most when unset).
     *
     * @param  list<Setting>  $fields
     *
     * @since 1.0.0
     */
    public static function repeater(string $key, string $label, string $description, string $since, array $fields): self
    {
        $allowed = [SettingType::Text, SettingType::Textarea, SettingType::Number, SettingType::Boolean, SettingType::Select, SettingType::MultiSelect, SettingType::Email, SettingType::Color, SettingType::Code];

        if ($fields === []) {
            throw new InvalidArgumentException("Repeater [{$key}] needs its fields.");
        }

        foreach ($fields as $field) {
            if (! in_array($field->type, $allowed, true)) {
                throw new InvalidArgumentException("Repeater [{$key}] can't hold a {$field->type->value} field [{$field->key}].");
            }
        }

        return new self($key, SettingType::Repeater, $label, $description, $since, [], fields: $fields);
    }

    /**
     * The field of a repeater whose value titles a collapsed item.
     *
     * @since 1.0.0
     */
    public function itemTitle(string $key): self
    {
        if ($this->type !== SettingType::Repeater || ! in_array($key, array_map(fn (Setting $field): string => $field->key, $this->fields), true)) {
            throw new InvalidArgumentException("Setting [{$this->key}] can't take its items' title from [{$key}].");
        }

        return $this->with(['itemTitle' => $key]);
    }

    /**
     * A code setting whose inline scripts wait for an administrator's
     * approval before they run.
     *
     * @since 1.0.0
     */
    public function scripts(): self
    {
        if ($this->type !== SettingType::Code) {
            throw new InvalidArgumentException("Only code settings hold scripts; [{$this->key}] is {$this->type->value}.");
        }

        return $this->with(['scripts' => true]);
    }

    /** @since 0.1.0 */
    public function default(mixed $value): self
    {
        return $this->with(['default' => $value]);
    }

    /** @since 0.1.0 */
    public function min(int $min): self
    {
        return $this->with(['min' => $min]);
    }

    /** @since 0.1.0 */
    public function max(int $max): self
    {
        return $this->with(['max' => $max]);
    }

    /** @since 0.1.0 */
    public function maxLength(int $maxLength): self
    {
        return $this->with(['maxLength' => $maxLength]);
    }

    /** @since 0.1.0 */
    public function required(): self
    {
        return $this->with(['required' => true]);
    }

    /** @since 0.1.0 */
    public function example(string $example): self
    {
        return $this->with(['example' => $example]);
    }

    /**
     * A server environment variable that, when set, supplies this secret and
     * wins over the stored value. Secrets only.
     *
     * @since 0.1.0
     */
    public function env(string $variable): self
    {
        if ($this->type !== SettingType::Secret) {
            throw new InvalidArgumentException("Only secret settings may name an environment variable; [{$this->key}] is {$this->type->value}.");
        }

        return $this->with(['env' => $variable]);
    }

    /**
     * The unit a number setting counts in, such as "days", shown beside its
     * field. Numbers only.
     *
     * @since 0.1.0
     */
    public function unit(string $unit): self
    {
        if ($this->type !== SettingType::Number) {
            throw new InvalidArgumentException("Only number settings may count in a unit; [{$this->key}] is {$this->type->value}.");
        }

        return $this->with(['unit' => $unit]);
    }

    /**
     * The fieldset the settings screen shows this setting under, one of
     * FIELDSETS.
     *
     * @since 0.1.0
     */
    public function fieldset(string $fieldset): self
    {
        if (! in_array($fieldset, self::FIELDSETS, true)) {
            throw new InvalidArgumentException("Setting [{$this->key}] names an unknown fieldset [{$fieldset}].");
        }

        return $this->with(['fieldset' => $fieldset]);
    }

    /**
     * Shows this setting only while every condition holds: another setting of
     * the same group has this value, or one of these values. Hiding is not
     * clearing; a hidden setting keeps its value.
     *
     * @param  array<string, scalar|list<scalar>>  $conditions  key => value, or key => [value, value]
     *
     * @since 0.1.0
     */
    public function visibleWhen(array $conditions): self
    {
        return $this->with(['visibleWhen' => $conditions]);
    }

    /**
     * The section of a plugin's settings page this setting sits in: the id of
     * one of the plugin's settingsSections.
     *
     * @since 1.0.0
     */
    public function section(string $id): self
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,39}$/D', $id) !== 1) {
            throw new InvalidArgumentException("Setting [{$this->key}] names a section whose id isn't 2 to 40 lower-case letters, digits and hyphens.");
        }

        return $this->with(['section' => $id]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
