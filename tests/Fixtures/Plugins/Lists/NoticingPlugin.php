<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Lists;

use Teme\Contracts\Status\ChecksStatus;
use Teme\Contracts\Status\StatusCheck;
use Teme\Contracts\Status\StatusNotice;

/**
 * A plugin object that raises the notice it is given, or none, through one
 * status check, as a plugin implementing ChecksStatus does.
 */
final readonly class NoticingPlugin implements ChecksStatus
{
    public function __construct(private ?StatusNotice $notice) {}

    public function statusChecks(): array
    {
        return [new class($this->notice) implements StatusCheck
        {
            public function __construct(private readonly ?StatusNotice $notice) {}

            public function notice(): ?StatusNotice
            {
                return $this->notice;
            }
        }];
    }
}
