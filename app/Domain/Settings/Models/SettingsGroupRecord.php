<?php

/**
 * The stored row for one settings group: only the values an administrator
 * changed, the encrypted secrets, a version for spotting conflicting edits,
 * and, for Settings › Design's groups, the unpublished draft. SettingsWriter
 * writes the live values; the design Operations write the draft.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * @property string $group_key
 * @property array<string, mixed> $overrides
 * @property array<string, string>|null $secrets
 * @property int $version
 * @property int|null $updated_by
 * @property array<string, mixed>|null $draft_values
 * @property int|null $draft_updated_by
 * @property CarbonInterface|null $draft_updated_at
 * @property array<string, array<string, mixed>>|null $translations
 */
final class SettingsGroupRecord extends Model
{
    public const CREATED_AT = null;

    protected $table = 'settings';

    protected $primaryKey = 'group_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'secrets' => 'encrypted:array',
            'version' => 'integer',
            'updated_at' => 'datetime',
            'draft_updated_at' => 'datetime',
        ];
    }

    /**
     * As overrides, but null means no draft: an empty draft is written as
     * `{}`, and null stays null.
     *
     * @return Attribute<array<string, mixed>|null, array<string, mixed>|null>
     */
    protected function draftValues(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?array => $value === null ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (?array $value): ?string => $value === null ? null : json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Words per language, `{"fr": {"tagline": "…"}}` (I-3360): null when
     * there are none, so an empty set is written as null, never as a list.
     *
     * @return Attribute<array<string, array<string, mixed>>|null, array<string, array<string, mixed>>|null>
     */
    protected function translations(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?array => $value === null ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (?array $value): ?string => $value === null || $value === [] ? null : json_encode($value, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Not an `array` cast: an empty PHP array encodes as a JSON list, and the
     * column is constrained to an object, so an empty set is written as `{}`.
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function overrides(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): array => json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (array $value): string => json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR),
        );
    }
}
