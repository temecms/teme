<?php

/**
 * One request to export or erase a person's personal data (I-3375): who it
 * is about, who asked and why, and what each holder answered, in the order
 * they ran. Only the privacy Operations write it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $kind
 * @property string|null $email
 * @property string $email_hash
 * @property int|null $user_id
 * @property string $status
 * @property int|null $requested_by
 * @property string $note
 * @property bool $reply
 * @property list<array<string, mixed>> $results
 * @property string|null $export_path
 * @property string|null $failure
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PrivacyRequest extends Model
{
    use HasUlids;

    public const string EXPORT = 'export';

    public const string ERASE = 'erase';

    public const string PENDING = 'pending';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    protected $table = 'privacy_requests';

    protected $guarded = [];

    /**
     * Returns the address's HMAC, keyed by the site's key: what the request
     * keeps once the address itself is gone, and what finds a person's
     * requests by address.
     */
    public static function hashOf(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'requested_by' => 'integer',
            'reply' => 'boolean',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The holders' answers, always written as a JSON list: a list with a gap
     * would encode as an object, which the table's check refuses.
     *
     * @return Attribute<list<array<string, mixed>>, array<int, array<string, mixed>>>
     */
    protected function results(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null ? [] : json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (array $value): string => json_encode(array_values($value), JSON_THROW_ON_ERROR),
        );
    }
}
