<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Types\ContentTypeRegistry;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingType;

/**
 * What a setting's value must be, and the form it is stored in, shared by
 * every writer: UpdateSettings and SettingsWriter for live values, and
 * SaveDesignDraft for drafts, so a draft holds each value exactly as
 * publishing it would store it.
 */
final class SettingRules
{
    /** An address on another site: http or https, a host, then anything without spaces. */
    private const string ADDRESS = '~^https?://[^\s/?#@]+(?:[/?#]\S*)?$~iu';

    /** The most terms one term setting holds. */
    public const int MOST_TERMS = 100;

    /** The longest a code setting may be. */
    public const int MOST_CODE = 65_535;

    /** The most items a repeater holds when its max isn't set. */
    public const int MOST_ITEMS = 20;

    /** An opening or closing time: 24-hour hours and minutes. */
    private const string TIME = '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/';

    /**
     * The validation rules for one setting's submitted value.
     *
     * @return list<mixed>
     */
    public static function for(Setting $setting): array
    {
        $presence = $setting->required ? 'required' : 'nullable';

        return match ($setting->type) {
            SettingType::Text, SettingType::Textarea => [
                $presence, 'string', ...($setting->maxLength !== null ? ['max:'.$setting->maxLength] : []),
            ],
            SettingType::Number => [
                $presence, 'integer',
                ...($setting->min !== null ? ['min:'.$setting->min] : []),
                ...($setting->max !== null ? ['max:'.$setting->max] : []),
            ],
            SettingType::Boolean => ['required', 'boolean'],
            SettingType::Select => [$presence, Rule::in(array_keys($setting->options))],
            SettingType::Url => [$presence, 'url', 'max:2048'],
            SettingType::Email => [$presence, 'email', 'max:255'],
            SettingType::Timezone => [$presence, 'timezone:all'],
            SettingType::Color => [$presence, 'regex:/^#[0-9a-fA-F]{6}$/'],
            SettingType::MultiSelect => ['present', 'array'],
            SettingType::Media => [$presence, 'string', self::image(...)],
            SettingType::Pairs => ['nullable', self::pairs($setting)],
            SettingType::Urls => ['nullable', self::urls($setting)],
            SettingType::Person => [$presence, 'string', self::person(...)],
            SettingType::Hours => ['nullable', self::hours(...)],
            SettingType::Term => $setting->multiple
                ? ['nullable', 'array', 'max:'.self::MOST_TERMS, self::terms($setting)]
                : [$presence, 'string', self::terms($setting)],
            SettingType::ContentType => [$presence, 'string', self::contentType(...)],
            SettingType::Secret => [],
            SettingType::Code => [$presence, 'string', 'max:'.self::MOST_CODE],
            // Its items one by one, under values.{key}.{n}.{field} (docs/plans/plugin-sockets-2.md, A5).
            SettingType::Repeater => ['present', 'array', 'list', 'min:'.($setting->min ?? 0), 'max:'.($setting->max ?? self::MOST_ITEMS)],
        };
    }

    /**
     * Checks a group's values as a form sends them: every key one of the
     * group's settings, never a secret, each value by its rules, a
     * multiselect's items among its options; throws a ValidationException
     * keyed `values.{key}`. $problems are the caller's own findings on the
     * rest of its form, reported with these.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $problems
     */
    public static function validateValues(SettingsGroup $group, array $values, array $problems = []): void
    {
        $rules = [];

        foreach ($values as $key => $value) {
            $setting = $group->setting((string) $key);

            if ($setting === null) {
                $problems["values.{$key}"] = 'This setting does not exist.';
            } elseif ($setting->type === SettingType::Secret) {
                $problems["values.{$key}"] = 'Secrets are submitted separately.';
            } else {
                $rules["values.{$key}"] = self::for($setting);

                if ($setting->type === SettingType::MultiSelect) {
                    $rules["values.{$key}.*"] = ['nullable', Rule::in(array_keys($setting->options))];
                }

                if ($setting->type === SettingType::Repeater) {
                    $values[$key] = $value === '' || $value === null ? [] : $value;
                    $rules["values.{$key}.*"] = ['array'];

                    foreach ($setting->fields as $field) {
                        $rules["values.{$key}.*.{$field->key}"] = self::for($field);

                        if ($field->type === SettingType::MultiSelect) {
                            $rules["values.{$key}.*.{$field->key}.*"] = ['nullable', Rule::in(array_keys($field->options))];
                        }
                    }
                }
            }
        }

        Validator::make(['values' => $values], $rules)
            ->after(function (ValidatorInstance $validator) use ($problems): void {
                foreach ($problems as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            })
            ->validate();
    }

    /**
     * A validated value in the form it is stored and read in: `"0"` becomes
     * false, a colour lower case, text trimmed.
     */
    public static function normalise(Setting $setting, mixed $raw): mixed
    {
        return match ($setting->type) {
            SettingType::Number => (int) $raw,
            SettingType::Boolean => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            SettingType::Select, SettingType::Timezone => (string) $raw,
            SettingType::Color => strtolower(trim((string) $raw)),
            // Keeps the declared option order and drops empties and
            // duplicates, so equal selections compare equal.
            SettingType::MultiSelect => array_values(array_intersect(
                array_keys($setting->options),
                array_map('strval', array_filter((array) $raw, fn (mixed $item): bool => $item !== null && $item !== '')),
            )),
            SettingType::Media => trim((string) $raw),
            SettingType::Pairs => self::rows($raw),
            SettingType::Urls => self::addresses($raw),
            SettingType::Person => is_scalar($raw) ? trim((string) $raw) : '',
            SettingType::Hours => self::week($raw),
            // Drops empties and duplicates, keeping the order chosen.
            SettingType::Term => $setting->multiple
                ? array_values(array_unique(array_map(strval(...), array_filter((array) $raw, fn (mixed $slug): bool => is_scalar($slug) && trim((string) $slug) !== ''))))
                : (is_scalar($raw) ? trim((string) $raw) : ''),
            SettingType::ContentType => is_scalar($raw) ? trim((string) $raw) : '',
            SettingType::Code => is_scalar($raw) ? (string) $raw : '',
            SettingType::Repeater => self::items($setting, $raw),
            default => trim((string) $raw),
        };
    }

    /**
     * A repeater's items as stored: a list, each item its fields' values,
     * each by its own type.
     *
     * @return list<array<string, mixed>>
     */
    private static function items(Setting $setting, mixed $raw): array
    {
        $items = [];

        foreach (is_array($raw) ? array_values($raw) : [] as $item) {
            $item = is_array($item) ? $item : [];
            $kept = [];

            foreach ($setting->fields as $field) {
                $value = $item[$field->key] ?? null;
                $kept[$field->key] = $value === null || ($value === '' && $field->type === SettingType::Number) ? $field->default : self::normalise($field, $value);
            }

            $items[] = $kept;
        }

        return $items;
    }

    /**
     * A media setting's value must be a live image in the library: never
     * a file of another kind, one in the trash or an ID nothing has. An
     * empty value clears the setting, and never reaches this rule.
     */
    private static function image(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! DB::table('media')->where('id', $value)->where('kind', 'image')->whereNull('deleted_at')->exists()) {
            $fail('Choose an image from the library.');
        }
    }

    /**
     * A pairs setting's rule: a list of at most max() rows, each with only a
     * name and a value, as text or empty, the name at most 100 characters
     * and the value at most maxLength(). One message for the field, naming
     * the first row that fails.
     */
    private static function pairs(Setting $setting): Closure
    {
        $most = $setting->max ?? 50;
        $longest = $setting->maxLength ?? 2000;
        $columns = array_map(mb_strtolower(...), $setting->options);

        return function (string $attribute, mixed $value, Closure $fail) use ($most, $longest, $columns): void {
            if (! is_array($value) || ! array_is_list($value)) {
                $fail('Send a list of rows, each with a name and a value.');

                return;
            }

            if (count($value) > $most) {
                $fail($most === 1 ? 'At most one row.' : "At most {$most} rows.");

                return;
            }

            foreach ($value as $index => $row) {
                $number = $index + 1;

                if (! is_array($row) || array_diff(array_keys($row), ['name', 'value']) !== [] || ! self::text($row['name'] ?? null) || ! self::text($row['value'] ?? null)) {
                    $fail('Send a list of rows, each with a name and a value.');

                    return;
                }

                foreach (['name' => 100, 'value' => $longest] as $part => $limit) {
                    if (mb_strlen(trim((string) ($row[$part] ?? ''))) > $limit) {
                        $fail("Row {$number}: the {$columns[$part]} is longer than {$limit} characters.");

                        return;
                    }
                }
            }
        };
    }

    /**
     * A pairs value as it is stored: each part trimmed, a row with both
     * parts empty dropped, the rest in the order given.
     *
     * @return list<array{name: string, value: string}>
     */
    private static function rows(mixed $raw): array
    {
        $rows = [];

        foreach (is_array($raw) ? $raw : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim(is_string($row['name'] ?? null) ? $row['name'] : '');
            $value = trim(is_string($row['value'] ?? null) ? $row['value'] : '');

            if ($name !== '' || $value !== '') {
                $rows[] = ['name' => $name, 'value' => $value];
            }
        }

        return $rows;
    }

    /**
     * Whether a pair's part is text or empty, as a browser sends it (an
     * empty field arrives as null).
     */
    private static function text(mixed $value): bool
    {
        return $value === null || is_string($value);
    }

    /**
     * A urls setting's rule: text of one address a line, or a list of
     * addresses, each http or https and at most 2,048 characters, none on
     * the site's own host, at most max() of them (20 when unset). One
     * message for the field, naming the first line that fails as the person
     * sees it, blank lines counted.
     */
    private static function urls(Setting $setting): Closure
    {
        $most = $setting->max ?? 20;

        return function (string $attribute, mixed $value, Closure $fail) use ($most): void {
            $lines = self::lines($value);

            if ($lines === null) {
                $fail('Send the addresses as text, one a line.');

                return;
            }

            $kept = [];

            foreach ($lines as $index => $line) {
                $line = trim($line);
                $number = $index + 1;

                if ($line === '') {
                    continue;
                }

                if (mb_strlen($line) > 2048) {
                    $fail("Line {$number} is longer than 2,048 characters.");

                    return;
                }

                if (preg_match(self::ADDRESS, $line) !== 1) {
                    $fail("Line {$number} isn’t a full address. Start it with https://.");

                    return;
                }

                if (self::onThisSite($line)) {
                    $fail("Line {$number} is on this site. Teme writes the site’s own addresses itself, so they stay right if it moves.");

                    return;
                }

                $kept[$line] = true;
            }

            if (count($kept) > $most) {
                $fail($most === 1 ? 'At most one address.' : "At most {$most} addresses.");
            }
        };
    }

    /**
     * A person setting's value must be someone with an account. An empty
     * value clears the setting, and never reaches this rule.
     */
    private static function person(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1 || ! DB::table('users')->where('id', (int) $value)->exists()) {
            $fail('Choose someone with an account here.');
        }
    }

    /**
     * A term setting's rule: each slug one of the setting's taxonomy's
     * terms. An empty value clears the setting, and never reaches this rule.
     */
    private static function terms(Setting $setting): Closure
    {
        $taxonomy = (string) $setting->taxonomy;

        return function (string $attribute, mixed $value, Closure $fail) use ($taxonomy): void {
            $slugs = array_values(array_unique(array_filter(is_array($value) ? $value : [$value], fn (mixed $slug): bool => $slug !== null && $slug !== '')));

            if (array_any($slugs, fn (mixed $slug): bool => ! is_string($slug))
                || DB::table('terms')->where('taxonomy', $taxonomy)->whereIn('slug', $slugs)->count() !== count($slugs)) {
                $fail(__('Choose terms this site has here.'));
            }
        };
    }

    /**
     * A type setting's value must be a content type with public addresses.
     * An empty value clears the setting, and never reaches this rule.
     */
    private static function contentType(string $attribute, mixed $value, Closure $fail): void
    {
        $types = app(ContentTypeRegistry::class);

        if (! is_string($value) || ! $types->has($value) || ! $types->get($value)->public) {
            $fail(__('Choose a content type this site shows to readers here.'));
        }
    }

    /**
     * An hours setting's rule (I-877): days of the week, each closed or with
     * one or two openings, each opening both its times in 24-hour HH:MM. An
     * opening may run past midnight, closing earlier than it opens, but never
     * closes the minute it opens; a second opening starts once the first has
     * closed, so the first can't run past midnight. Openings with both times
     * empty are ignored, and closed wins over any times. One message, naming
     * the day.
     */
    private static function hours(string $attribute, mixed $value, Closure $fail): void
    {
        $shape = 'Send the week as days, each with its openings or closed.';

        if (! is_array($value) || array_diff(array_map(strval(...), array_keys($value)), Setting::DAYS) !== []) {
            $fail($shape);

            return;
        }

        foreach (Setting::DAYS as $day) {
            if (! array_key_exists($day, $value)) {
                continue;
            }

            $entry = $value[$day];

            if (! is_array($entry)) {
                $fail($shape);

                return;
            }

            if (self::isClosed($entry)) {
                continue;
            }

            $openings = self::openings($entry['hours'] ?? []);

            if ($openings === null) {
                $fail($shape);

                return;
            }

            $problem = self::dayProblem($openings);

            if ($problem !== null) {
                $fail(ucfirst($day).': '.$problem);

                return;
            }
        }
    }

    /**
     * What is wrong with one day's openings, or null when nothing is.
     *
     * @param  list<array{opens: string, closes: string}>  $openings
     */
    private static function dayProblem(array $openings): ?string
    {
        if (count($openings) > 2) {
            return 'two openings a day at most.';
        }

        foreach ($openings as $opening) {
            if ($opening['opens'] === '' || $opening['closes'] === '') {
                return 'give each opening both its times.';
            }

            if (preg_match(self::TIME, $opening['opens']) !== 1 || preg_match(self::TIME, $opening['closes']) !== 1) {
                return 'write each time as hours and minutes in 24-hour time, such as 09:00 or 17:30.';
            }

            if ($opening['opens'] === $opening['closes']) {
                return 'an opening can’t close the minute it opens.';
            }
        }

        // HH:MM compares as text in time order.
        if (count($openings) === 2 && ($openings[0]['closes'] < $openings[0]['opens'] || $openings[1]['opens'] < $openings[0]['closes'])) {
            return 'the second opening must start once the first has closed.';
        }

        return null;
    }

    /**
     * A day's openings as a form sends them, each time trimmed, those with
     * both times empty left out; null when one isn't an opening at all. A
     * browser's empty time arrives as null.
     *
     * @return list<array{opens: string, closes: string}>|null
     */
    private static function openings(mixed $hours): ?array
    {
        if (! is_array($hours)) {
            return null;
        }

        $openings = [];

        foreach ($hours as $opening) {
            if (! is_array($opening) || ! self::text($opening['opens'] ?? null) || ! self::text($opening['closes'] ?? null)) {
                return null;
            }

            $opens = trim((string) ($opening['opens'] ?? ''));
            $closes = trim((string) ($opening['closes'] ?? ''));

            if ($opens !== '' || $closes !== '') {
                $openings[] = ['opens' => $opens, 'closes' => $closes];
            }
        }

        return $openings;
    }

    /**
     * Whether a day, as a form sends it, is ticked closed.
     *
     * @param  array<array-key, mixed>  $entry
     */
    private static function isClosed(array $entry): bool
    {
        return filter_var($entry['closed'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * An hours value as it is stored: the days that say something, Monday
     * first, each `day`, `hours` and `closed` in that order, each opening
     * `opens` then `closes`. PostgreSQL's jsonb keeps an object's keys
     * shortest first, so this is the order a stored week reads back in, and
     * the same week saved again is no change to SettingsWriter.
     *
     * @return list<array{day: string, hours: list<array{opens: string, closes: string}>, closed: bool}>
     */
    private static function week(mixed $raw): array
    {
        $week = [];

        foreach (Setting::DAYS as $day) {
            $entry = is_array($raw) ? ($raw[$day] ?? null) : null;

            if (! is_array($entry)) {
                continue;
            }

            $closed = self::isClosed($entry);
            $hours = $closed ? [] : (self::openings($entry['hours'] ?? []) ?? []);

            if ($closed || $hours !== []) {
                $week[] = ['day' => $day, 'hours' => $hours, 'closed' => $closed];
            }
        }

        return $week;
    }

    /**
     * A urls value as it is stored: each line trimmed, blank lines and
     * repeats dropped, the rest in the order given.
     *
     * @return list<string>
     */
    private static function addresses(mixed $raw): array
    {
        $kept = [];

        foreach (self::lines($raw) ?? [] as $line) {
            $line = trim($line);

            if ($line !== '' && ! in_array($line, $kept, true)) {
                $kept[] = $line;
            }
        }

        return $kept;
    }

    /**
     * A urls value's lines: a textarea's text split at its line breaks (a
     * browser sends `\r\n`), or a list of addresses; null for anything else.
     *
     * @return list<string>|null
     */
    private static function lines(mixed $value): ?array
    {
        return match (true) {
            $value === null => [],
            is_string($value) => preg_split('/\r\n|\r|\n/', $value) ?: [],
            is_array($value) && array_is_list($value) && array_filter($value, fn (mixed $line): bool => ! is_string($line)) === [] => $value,
            default => null,
        };
    }

    /**
     * Whether an address is on the site's own host, whose addresses the
     * site makes itself at render, so they follow it when it moves.
     */
    private static function onThisSite(string $address): bool
    {
        $host = parse_url($address, PHP_URL_HOST);

        return is_string($host) && mb_strtolower($host) === mb_strtolower((string) parse_url(url('/'), PHP_URL_HOST));
    }
}
