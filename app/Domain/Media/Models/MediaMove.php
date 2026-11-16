<?php

declare(strict_types=1);

namespace App\Domain\Media\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of teme:media:move (I-3456): a copy between two media locations,
 * or the deletion of the source's copies after one, how far it got and what
 * it found. Only the three move Operations write it.
 *
 * @property string $id
 * @property string $kind copy | delete_source
 * @property string $from_location
 * @property string $to_location
 * @property list<string> $roles
 * @property string $scope all | changed
 * @property CarbonImmutable|null $changed_since
 * @property bool $deep
 * @property array<string, string> $site_disks
 * @property string $state running | stopped | finished
 * @property string|null $cursor
 * @property int $items_total
 * @property int $items_done
 * @property int $files_copied
 * @property int $files_present
 * @property int $files_deleted
 * @property int $bytes_copied
 * @property int $holes
 * @property int $failures
 * @property list<array{id: string, role: string, path: string, reason: string}> $problems
 * @property string|null $message
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
final class MediaMove extends Model
{
    use HasUlids;

    /** The most problems a run names; it counts them all in failures. */
    public const int NAMED_PROBLEMS = 1000;

    protected $table = 'media_moves';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'problems' => 'array',
            'changed_since' => 'immutable_datetime',
            'deep' => 'boolean',
            'items_total' => 'integer',
            'items_done' => 'integer',
            'files_copied' => 'integer',
            'files_present' => 'integer',
            'files_deleted' => 'integer',
            'bytes_copied' => 'integer',
            'holes' => 'integer',
            'failures' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * Not an `array` cast: the column holds an object, and an empty PHP
     * array would encode as a list.
     *
     * @return Attribute<array<string, string>, array<string, string>>
     */
    protected function siteDisks(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null ? [] : (array) json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (array $value): string => $value === [] ? '{}' : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
