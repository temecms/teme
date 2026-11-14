<?php

declare(strict_types=1);

namespace App\Http\Standalone;

use App\Domain\Navigation\Queries\ShellBrand;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

/**
 * What the pages drawn outside the admin's app print around their words: the
 * site's mark, or Teme's when the site has none or cannot be read, and the
 * way back (docs/proposals/sign-in-pages.md).
 */
final readonly class StandalonePage
{
    public function __construct(
        private SettingsReader $settings,
        private ShellBrand $brand,
    ) {}

    /**
     * The site's name and its letter mark; both null when the site has no
     * name or it cannot be read, so the page draws Teme's mark instead.
     *
     * @return array{name: string|null, mark: string|null}
     */
    public function brand(): array
    {
        try {
            $name = trim($this->settings->string('general', 'site_title'));

            if ($name === '') {
                return ['name' => null, 'mark' => null];
            }

            return ['name' => $name, 'mark' => $this->brand->forViewer(false)['mark']];
        } catch (Throwable) {
            return ['name' => null, 'mark' => null];
        }
    }

    /**
     * Teme's icon as inline markup, hidden from screen readers and 36 pixels
     * square, its class names prefixed so its style touches nothing else on
     * the page; an empty string when the file cannot be read.
     */
    public function temeMark(): string
    {
        $svg = (string) @file_get_contents(public_path('teme/icon.svg'));

        if ($svg === '') {
            return '';
        }

        $svg = (string) preg_replace('#<title>.*?</title>\s*#s', '', $svg);
        // Its own class names, prefixed: inline, its style reaches the whole page.
        $svg = (string) preg_replace('/\.(tile|edge|deep|light|pale)\b/', '.sa-icon-$1', $svg);
        $svg = (string) preg_replace('/class="(tile|edge|deep|light|pale)"/', 'class="sa-icon-$1"', $svg);

        return (string) preg_replace('/<svg\b/', '<svg aria-hidden="true" focusable="false" width="36" height="36"', $svg, 1);
    }

    /**
     * Where the page's one action leads: Home for an address under the
     * admin's own path, the site's home page for any other.
     *
     * @return array{url: string, label: string}
     */
    public function home(): array
    {
        $admin = trim((string) parse_url(route('dashboard'), PHP_URL_PATH), '/');
        $path = trim(request()->path(), '/');

        if ($admin !== '' && ($path === $admin || str_starts_with($path, $admin.'/'))) {
            return ['url' => route('dashboard'), 'label' => __('Back to Home')];
        }

        return ['url' => url('/'), 'label' => __('Go to the home page')];
    }
}
