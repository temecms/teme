<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PluginRecordFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * One plugin's stored row (I-3353): enabled or disabled and why, the
 * version and access the person agreed to, and its listeners' recent faults
 * and stopped parts. EnablePlugin and DisablePlugin write it; every other
 * state is worked out from the plugin on disk.
 *
 * @property string $id
 * @property string $name
 * @property string $code
 * @property string $state
 * @property string|null $disabled_reason
 * @property string $version
 * @property array{capabilities?: list<string>, hosts?: list<string>, hooks?: list<string>} $consent
 * @property int|null $consented_by
 * @property CarbonImmutable $consented_at
 * @property CarbonImmutable|null $enabled_at
 * @property CarbonImmutable|null $disabled_at
 * @property int|null $disabled_by
 * @property array<string, array<string, mixed>> $faults
 * @property array<string, array<string, mixed>> $quarantine
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PluginRecord extends Model
{
    /** @use HasFactory<PluginRecordFactory> */
    use HasFactory;

    use HasUlids;

    public const string ENABLED = 'enabled';

    public const string DISABLED = 'disabled';

    /** Disabled by a person, or the operator. */
    public const string BY_USER = 'by_user';

    /** Disabled by Teme, after it failed to start five times. */
    public const string QUARANTINED = 'quarantined';

    protected $table = 'plugins';

    protected $guarded = [];

    protected static function newFactory(): PluginRecordFactory
    {
        return PluginRecordFactory::new();
    }

    protected function casts(): array
    {
        return [
            'consented_by' => 'integer',
            'disabled_by' => 'integer',
            'consented_at' => 'immutable_datetime',
            'enabled_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function consent(): Attribute
    {
        return $this->jsonObject();
    }

    /**
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function faults(): Attribute
    {
        return $this->jsonObject();
    }

    /**
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function quarantine(): Attribute
    {
        return $this->jsonObject();
    }

    /**
     * Not an `array` cast: an empty PHP array encodes as a JSON list, and
     * the columns are constrained to objects, so an empty one is written as `{}`.
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    private function jsonObject(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null ? [] : json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (array $value): string => json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR),
        );
    }
}
