<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Lists;

use RuntimeException;
use Teme\Contracts\Status\StatusCheck;
use Teme\Contracts\Status\StatusNotice;

/** A plugin's status check that fails every time it is asked. */
final class ThrowingCheck implements StatusCheck
{
    public function notice(): ?StatusNotice
    {
        throw new RuntimeException('The workspace answered nothing.');
    }
}
