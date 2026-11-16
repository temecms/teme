<?php

declare(strict_types=1);

namespace App\View\Components\Teme;

use App\Domain\Site\Policy\PagePolicy;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-teme::inline-script>`: one of core's own inline scripts,
 * written exactly as its hash is noted in the page's policy, so the page
 * stays shared and a policy allows it without a nonce. Core's alone: a
 * theme's scripts go in its build, which teme:theme:check holds it to.
 */
final class InlineScript extends Component
{
    /**
     * @param  string  $name  the script's data-teme marker, by which core's tests and scripts find it
     * @param  string  $type  the script's type, such as speculationrules; none for a classic script
     */
    public function __construct(
        private readonly PagePolicy $policy,
        public string $name = '',
        public string $type = '',
    ) {}

    public function render(): View
    {
        return view('teme::components.inline-script');
    }

    /**
     * The attributes of the opening tag: the type, then data-teme, each
     * escaped, or '' for none.
     */
    public function openingAttributes(): string
    {
        return ($this->type === '' ? '' : ' type="'.e($this->type).'"')
            .($this->name === '' ? '' : ' data-teme="'.e($this->name).'"');
    }

    /**
     * The script's text as it is printed between the tags, its hash noted
     * for the page's policy first. The only way a hash reaches the policy
     * from a view.
     */
    public function allowed(Htmlable|string $body): string
    {
        $text = $body instanceof Htmlable ? $body->toHtml() : $body;
        $this->policy->allowScript($text);

        return $text;
    }
}
