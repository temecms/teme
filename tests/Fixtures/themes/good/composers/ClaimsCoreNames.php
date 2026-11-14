<?php

declare(strict_types=1);

namespace Tests\Fixtures\Themes\Good;

use Teme\Contracts\Themes\ViewComposer;
use Teme\Contracts\Themes\ViewContext;

/**
 * The reserving fixture theme's composer: each of its partials is given the
 * key the partial is named for, so the tests can see a theme return a name
 * core keeps for itself (I-3360), and a new name of its own. It sits here
 * because the good fixture's folder is the one the tests' autoloader maps.
 */
final class ClaimsCoreNames implements ViewComposer
{
    public function views(): array
    {
        return ['partials.*'];
    }

    public function with(ViewContext $context): array
    {
        $key = substr($context->view(), strlen('partials.'));

        return [$key => $key === 'alternates' ? [] : $key];
    }
}
