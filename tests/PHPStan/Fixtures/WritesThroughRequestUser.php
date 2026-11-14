<?php

declare(strict_types=1);

namespace App\Http\Fixtures;

use Illuminate\Http\Request;

final class WritesThroughRequestUser
{
    public function update(Request $request): void
    {
        $request->user()->fill(['name' => 'Changed']);
        $request->user()->save();
        $request->user()?->delete();
    }
}
