<?php

declare(strict_types=1);

namespace App\Domain\Themes;

use Illuminate\Contracts\View\View;
use Teme\Contracts\Themes\ViewContext;

/**
 * What a composer may know about the theme view it composes: its name, the
 * page's kind and whether it is the home page, from the request's
 * ViewIntent, the site's locale, and the view's data so far, read live. A
 * view made with no page behind it has the kind '' and is not the home page.
 */
final readonly class ThemeContext implements ViewContext
{
    public function __construct(
        private string $view,
        private ?ViewIntent $intent,
        private string $locale,
        private View $composing,
    ) {}

    public function view(): string
    {
        return $this->view;
    }

    public function kind(): string
    {
        return $this->intent === null ? '' : $this->intent->kind;
    }

    public function isFront(): bool
    {
        return $this->intent !== null && $this->intent->isFront;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->composing->getData());
    }

    public function get(string $key): mixed
    {
        return $this->composing->getData()[$key] ?? null;
    }
}
