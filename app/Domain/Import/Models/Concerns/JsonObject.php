<?php

declare(strict_types=1);

namespace App\Domain\Import\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use stdClass;

/**
 * A JSONB column that must hold an object: an empty array is written as
 * {}, never [], which its CHECK would refuse (.ai/rules/models.md).
 */
trait JsonObject
{
    /**
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected static function jsonObject(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null ? [] : (array) json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (array $value): string => json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR),
        );
    }
}
