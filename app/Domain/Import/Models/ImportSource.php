<?php

declare(strict_types=1);

namespace App\Domain\Import\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A WordPress site Teme imports from, keyed by its own home address. Its
 * application password is encrypted and never serialized, and is written
 * only by the import Operations (I-1708).
 *
 * @property string $id
 * @property string $address
 * @property string $api_root
 * @property string|null $user_name
 * @property string|null $credential
 * @property string $plugin_version
 * @property int $api_version
 * @property list<string> $other_hosts
 * @property CarbonImmutable $probed_at
 * @property CarbonImmutable|null $forgotten_at
 * @property int|null $created_by
 */
final class ImportSource extends Model
{
    use HasUlids;

    protected $table = 'import_sources';

    protected $guarded = [];

    protected $hidden = ['credential'];

    protected function casts(): array
    {
        return [
            'credential' => 'encrypted',
            'api_version' => 'integer',
            'created_by' => 'integer',
            'other_hosts' => 'array',
            'probed_at' => 'immutable_datetime',
            'forgotten_at' => 'immutable_datetime',
        ];
    }

    /**
     * The imports of this site.
     *
     * @return HasMany<ImportRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ImportRun::class, 'source_id');
    }
}
