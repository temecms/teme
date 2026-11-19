<?php

/**
 * One of the site's own fediverse accounts (I-058): the blog, an author or
 * the application. Its address is stored verbatim and never changes, and
 * its private key is encrypted under APP_KEY and hidden from every array.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Models;

use App\Domain\Fediverse\SiteActors;
use App\Domain\People\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $kind blog, person or application
 * @property string|null $person_id
 * @property string $uri
 * @property string $username
 * @property array{inbox?: string, outbox?: string, followers?: string, following?: string} $urls
 * @property list<string> $aliases
 * @property string $public_key_pem
 * @property string $private_key
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class FediverseActor extends Model
{
    use HasUlids;

    public const string BLOG = 'blog';

    public const string PERSON = 'person';

    public const string APPLICATION = 'application';

    protected $table = 'fediverse_actors';

    protected $guarded = [];

    protected $hidden = ['private_key'];

    protected static function booted(): void
    {
        self::created(fn () => SiteActors::changed());
        self::deleted(fn () => SiteActors::changed());
    }

    protected function casts(): array
    {
        return [
            'urls' => 'array',
            'aliases' => 'array',
            'private_key' => 'encrypted',
            'enabled' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The person this actor speaks for, when it is an author's.
     *
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * The address of its public key, as remote servers name it in a signature.
     */
    public function keyId(): string
    {
        return $this->uri.'#main-key';
    }
}
