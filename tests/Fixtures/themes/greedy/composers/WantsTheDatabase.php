<?php

declare(strict_types=1);

namespace Tests\Fixtures\Themes\Greedy;

use Illuminate\Database\DatabaseManager;
use Teme\Contracts\Themes\ViewComposer;
use Teme\Contracts\Themes\ViewContext;

/**
 * The greedy fixture theme's composer: it asks for the database, which a
 * theme composer may not, so the theme is broken.
 */
final class WantsTheDatabase implements ViewComposer
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function views(): array
    {
        return ['*'];
    }

    public function with(ViewContext $context): array
    {
        return ['connection' => $this->database->getDefaultConnection()];
    }
}
