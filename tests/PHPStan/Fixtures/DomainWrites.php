<?php

declare(strict_types=1);

namespace App\Domain\Fixtures;

use App\Models\User;

final class DomainWrites
{
    public function handle(): void
    {
        User::create(['name' => 'x', 'email' => 'x@example.com', 'password' => 'unused-password-value']);
    }
}
