<?php

declare(strict_types=1);

namespace App\Domain\Navigation\Queries;

use Illuminate\Contracts\Foundation\Application;
use Teme\Contracts\Settings\SettingsReader;

/**
 * What the admin's shell prints about this installation: the letter that
 * marks the site, and the product, version and environment under its name.
 * Someone who has not signed in is not told the version.
 */
final readonly class ShellBrand
{
    public function __construct(
        private SettingsReader $settings,
        private Application $app,
    ) {}

    /**
     * The brand the shell prints for this viewer: the site's mark, the
     * product, its version (null for someone not signed in) and the
     * environment.
     *
     * @return array{mark: string, product: string, version: string|null, environment: string}
     */
    public function forViewer(bool $signedIn): array
    {
        return [
            'mark' => $this->mark($this->settings->string('general', 'site_title')),
            'product' => 'Teme',
            'version' => $signedIn ? (string) config('teme.version') : null,
            'environment' => (string) $this->app->environment(),
        ];
    }

    /**
     * The title's first letter or digit in capitals, so a leading quotation
     * mark or dash never becomes the mark; T for Teme when the title has none.
     * Only one letter is kept: the capital of a sharp s is two.
     */
    private function mark(string $title): string
    {
        if (preg_match('/[\p{L}\p{N}]/u', $title, $first) !== 1) {
            return 'T';
        }

        return mb_substr(mb_strtoupper($first[0]), 0, 1);
    }
}
