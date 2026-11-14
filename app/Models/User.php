<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Users\Notifications\ResetPasswordLink;
use App\Domain\Users\UserSlugs;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;
use stdClass;

/**
 * A person who can sign in to the admin. Its roles come from the permission
 * tables, and only the user Operations write it, apart from Fortify's own
 * credential fields.
 *
 * @property string $slug
 * @property CarbonInterface|null $invited_at
 * @property CarbonInterface|null $suspended_at
 * @property array<string, mixed>|null $preferences
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordLink($token, $this->id));
    }

    /**
     * Every new account gets its author slug here, the one step invitations,
     * the seeders and the factories all pass through.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (($user->slug ?? '') === '') {
                $user->slug = UserSlugs::unique((string) $user->name);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'invited_at' => 'datetime',
            'suspended_at' => 'datetime',
            'preferences' => 'array',
        ];
    }

    /**
     * Read by the `array` cast; written as `{}` when empty, because the column
     * is constrained to an object and an empty PHP array encodes as a list.
     *
     * @return Attribute<never, array<string, mixed>>
     */
    protected function preferences(): Attribute
    {
        return Attribute::make(
            set: fn (array $value): string => json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR),
        );
    }
}
