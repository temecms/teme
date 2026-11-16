<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Models;

use App\Domain\Extensions\Sources\ExtensionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * One request to install, update, remove, roll back or rebuild plugins and
 * themes, and how the installer carried it out. Written only by the
 * extension Operations; one may be open at a time.
 *
 * @property string $id
 * @property string $action
 * @property string $kind
 * @property string|null $package
 * @property string|null $constraint
 * @property string|null $source_kind
 * @property string|null $source_id
 * @property string|null $upload_id
 * @property string $status
 * @property string|null $step
 * @property list<array<string, mixed>> $steps
 * @property array<string, mixed> $needs
 * @property array<string, mixed> $agreed
 * @property array<string, mixed> $report
 * @property string|null $error
 * @property string|null $from_release
 * @property string|null $to_release
 * @property int|null $requested_by
 * @property int|null $agreed_by
 * @property int|null $cancelled_by
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
final class ExtensionRequestRecord extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'extension_requests';

    protected $guarded = [];

    /** The request as a value, for the installer and its sources. */
    public function toRequest(): ExtensionRequest
    {
        return ExtensionRequest::fromRow((object) [
            ...$this->attributesToArray(),
            'requested_at' => $this->requested_at->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ]);
    }

    /** @return Attribute<array<string, mixed>, array<string, mixed>> */
    protected function needs(): Attribute
    {
        return self::jsonObject();
    }

    /** @return Attribute<array<string, mixed>, array<string, mixed>> */
    protected function agreed(): Attribute
    {
        return self::jsonObject();
    }

    /** @return Attribute<array<string, mixed>, array<string, mixed>> */
    protected function report(): Attribute
    {
        return self::jsonObject();
    }

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'requested_by' => 'integer',
            'agreed_by' => 'integer',
            'cancelled_by' => 'integer',
            'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * A JSONB column that must hold an object: an empty array is written as
     * `{}`, never as a list (.ai/rules/models.md).
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    private static function jsonObject(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null ? [] : (array) json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (array $value): string => json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR),
        );
    }
}
