<?php

declare(strict_types=1);

namespace Tests\Fixtures\Languages;

use Teme\Contracts\Themes\ViewComposer;
use Teme\Contracts\Themes\ViewContext;

/**
 * The language fixture theme's composer: hands its template the locale a
 * theme's composer is told, so a test reads it (I-3360).
 */
final class TellsLocale implements ViewComposer
{
    public function views(): array
    {
        return ['templates.*'];
    }

    public function with(ViewContext $context): array
    {
        return ['toldLocale' => $context->locale()];
    }
}
