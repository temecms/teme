<?php

declare(strict_types=1);

namespace App\Http\Fixtures;

use Illuminate\Http\Request;

final class ReadsOnly
{
    public function show(Request $request): string
    {
        return (string) $request->user()?->name;
    }
}
