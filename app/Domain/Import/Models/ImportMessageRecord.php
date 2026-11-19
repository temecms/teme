<?php

/**
 * A warning or error an import run met, tied to an object's map row or to
 * the run alone. Written only by the import Operations (I-345).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Models;

use App\Domain\Import\ImportMessageLevel;
use App\Domain\Import\Models\Concerns\JsonObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $run_id
 * @property string|null $map_id
 * @property ImportMessageLevel $level
 * @property string $code
 * @property string $message
 * @property array<string, mixed> $context
 * @property CarbonImmutable $created_at
 */
final class ImportMessageRecord extends Model
{
    use HasUlids;
    use JsonObject;

    public const UPDATED_AT = null;

    protected $table = 'import_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'level' => ImportMessageLevel::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * The flat facts about the message; never personal data.
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function context(): Attribute
    {
        return self::jsonObject();
    }
}
