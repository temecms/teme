<?php

declare(strict_types=1);

namespace App\Actions\Fixtures;

use App\Models\User;

final class CreatesUserStatically
{
    public function handle(): void
    {
        User::create(['name' => 'x', 'email' => 'x@example.com', 'password' => 'unused-password-value']);
        User::query()->whereKey(1)->update(['name' => 'y']);
    }
}
