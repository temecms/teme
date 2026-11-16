<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Librarian;

use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\NewItem;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Writes one draft for the person the run acts for, titled from the
 * payload, so a test sees who the run said it acted for.
 */
final class WriteAsPersonHandler implements JobHandler
{
    public function __construct(private ContentWriter $writer) {}

    public function handle(JobContext $context): void
    {
        $title = $context->payload()['title'] ?? 'Written by the librarian';

        $this->writer->create(new NewItem('post', (string) $title, ['type' => 'doc', 'content' => []]), $context->personId());
    }
}
