<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Reaching;

use App\Domain\Contents\Models\Content;
use App\Domain\Users\Actors;

/**
 * Names Teme’s own classes three times: twice imported, once in a string.
 */
final class Listens
{
    /** @return list<string> */
    public function models(): array
    {
        return [Content::class, Actors::class, 'App\\Models\\User'];
    }
}
