<?php

declare(strict_types=1);

namespace Tests\Fixtures\Themes\Good;

use Teme\Contracts\Themes\ViewComposer;
use Teme\Contracts\Themes\ViewContext;

/**
 * The good fixture theme's composer: a greeting for its single and archive
 * templates, so the tests can see a theme's own composer run.
 */
final class Greets implements ViewComposer
{
    public function views(): array
    {
        return ['templates.single', 'templates.archive'];
    }

    public function with(ViewContext $context): array
    {
        return ['greeting' => 'hello'];
    }
}
