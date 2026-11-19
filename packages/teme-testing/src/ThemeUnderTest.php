<?php

/**
 * One theme on the test's site: checked as `teme:theme:check` checks it,
 * and made the one the site is drawn with through ActivateTheme.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Testing;

use App\Domain\Design\Operations\ActivateTheme;
use App\Domain\Design\Operations\Input\ActivateThemeInput;
use App\Domain\Themes\ActiveTheme;
use Closure;
use Illuminate\Support\Facades\Artisan;

final readonly class ThemeUnderTest
{
    /**
     * @param  Closure(): Person  $administrator  a new administrator, for a step no one is named for
     */
    public function __construct(
        public string $code,
        private Acting $acting,
        private Closure $administrator,
    ) {}

    /**
     * Returns the problems `teme:theme:check` finds in the theme, each as it
     * words it; empty when the theme passes.
     *
     * @return list<string>
     */
    public function check(): array
    {
        Artisan::call('teme:theme:check', ['code' => $this->code, '--json' => true]);
        $report = json_decode(Artisan::output(), true);

        return is_array($report) && is_array($report['errors'] ?? null)
            ? array_values(array_map(strval(...), $report['errors']))
            : ['teme:theme:check gave no report.'];
    }

    /**
     * Makes the theme the one the public site is drawn with, through
     * ActivateTheme as this person, a new administrator when none is named.
     * Fails the test with the refusal.
     */
    public function activate(?Person $as = null): self
    {
        $as ??= ($this->administrator)();

        $this->acting->as($as, fn (): mixed => app(ActivateTheme::class)->execute(new ActivateThemeInput($this->code, (string) $as->id)), 'Activating '.$this->code);
        app(ActiveTheme::class)->forget();

        return $this;
    }
}
