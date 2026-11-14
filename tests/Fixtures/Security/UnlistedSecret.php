<?php

declare(strict_types=1);

namespace Tests\Fixtures\Security;

use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Model;

/**
 * A model with two encrypted casts nobody registered, which the registry's
 * test must find. It has no table: nothing reads or writes it.
 */
final class UnlistedSecret extends Model
{
    protected $table = 'unlisted_secrets';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'settings' => AsEncryptedArrayObject::class,
        ];
    }
}
