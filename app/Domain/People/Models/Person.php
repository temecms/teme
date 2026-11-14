<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone an item can credit (I-3366): an account's own person, whose name,
 * slug and biography are the account's, or a person with no account, who
 * holds their own. Only the people Operations write them.
 *
 * @property string $id
 * @property int|null $user_id the account this person is, or null
 * @property string|null $name a person with no account's own; null for an account's
 * @property string|null $slug a person with no account's own; null for an account's
 * @property string|null $bio a person with no account's own; null for an account's
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Person extends Model
{
    use HasUlids;

    protected $table = 'people';

    protected $guarded = [];

    /**
     * The account this person is, when they have one.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
