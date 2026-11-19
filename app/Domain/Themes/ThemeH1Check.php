<?php

/**
 * The theme checker's one-H1 rule: a published single item's page and the
 * home page, each drawn with the theme being checked, must have exactly one
 * `<h1>`. The caller draws the pages, so they take the public site's own
 * rendering path; this class picks the item, makes the theme the one drawing
 * while it does, and counts the headings.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

use App\Domain\Addresses\Addresses;
use Closure;
use DOMDocument;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

final class ThemeH1Check
{
    public function __construct(private readonly ActiveTheme $active, private readonly ThemeRegistry $themes) {}

    /**
     * What the check finds for this theme: the errors, and the warnings for
     * a page it could not check. A theme that cannot draw the site is left
     * to the checker's other errors.
     *
     * @param  Closure(string): string  $draw  the HTML the public site answers at an address
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function run(Theme $theme, Closure $draw): array
    {
        if (! $theme->usable()) {
            return ['errors' => [], 'warnings' => []];
        }

        $errors = [];
        $warnings = [];

        try {
            $single = $this->singlePath();
        } catch (Throwable) {
            // No readable database, such as a theme's own CI: no page can be drawn.
            return ['errors' => [], 'warnings' => [__('The pages were not checked for an <h1>: the site\'s database could not be read.')]];
        }

        $pages = ['The home page' => '/'];

        $single === null
            ? $warnings[] = __('The single item page was not checked for an <h1>: no item is published.')
            : $pages[__('The single item page :path', ['path' => $single])] = $single;

        $this->using($theme, function () use ($pages, $draw, &$errors): void {
            foreach ($pages as $label => $path) {
                try {
                    $count = self::count($draw($path));
                } catch (Throwable $failure) {
                    $errors[] = __(':where could not be drawn to count its <h1>: :reason', ['where' => $label, 'reason' => $failure->getMessage()]);

                    continue;
                }

                if ($count === 0) {
                    $errors[] = __(':where has no H1.', ['where' => $label]);
                } elseif ($count > 1) {
                    $errors[] = __(':where has more than one H1 (:count).', ['where' => $label, 'count' => $count]);
                }
            }
        });

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * How many `<h1>` elements the document has, as a browser would read it.
     */
    public static function count(string $html): int
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document->getElementsByTagName('h1')->length;
    }

    /**
     * The address of the latest published item anyone may read, a post by
     * preference, or null when there is none.
     */
    private function singlePath(): ?string
    {
        $row = DB::table('contents')
            ->join('paths', fn ($join) => $join->on('paths.target_id', '=', DB::raw('contents.id::text'))->where('paths.target_type', 'content')->where('paths.is_current', true))
            ->where('contents.status', 'published')
            ->whereNull('contents.deleted_at')
            ->whereNotIn('contents.visibility', ['private', 'protected'])
            ->where('paths.path', '<>', '')
            ->orderByRaw("contents.type = 'post' desc")
            ->orderByDesc('contents.published_at')
            ->first(['paths.path', 'contents.type']);

        return $row === null ? null : Addresses::toUrl((string) $row->path, (string) $row->type);
    }

    /**
     * Runs the callback with this theme as the one drawing the site, then
     * gives the site its own theme back.
     */
    private function using(Theme $theme, Closure $callback): void
    {
        if ($this->active->chosen() === $theme->code) {
            $callback();

            return;
        }

        $settings = app(SettingsReader::class);
        $chosen = new ActiveTheme($this->themes, new class($settings, $theme->code) implements SettingsReader
        {
            public function __construct(private readonly SettingsReader $settings, private readonly string $code) {}

            public function group(string $group): array
            {
                return $this->settings->group($group);
            }

            public function get(string $group, string $key): mixed
            {
                return $group === 'design' && $key === 'theme' ? $this->code : $this->settings->get($group, $key);
            }

            public function string(string $group, string $key): string
            {
                return $group === 'design' && $key === 'theme' ? $this->code : $this->settings->string($group, $key);
            }

            public function int(string $group, string $key): int
            {
                return $this->settings->int($group, $key);
            }

            public function bool(string $group, string $key): bool
            {
                return $this->settings->bool($group, $key);
            }

            public function stringList(string $group, string $key): array
            {
                return $this->settings->stringList($group, $key);
            }
        });

        try {
            app()->instance(ActiveTheme::class, $chosen);
            app()->forgetInstance(ComposerRegistry::class);
            $callback();
        } finally {
            app()->instance(ActiveTheme::class, $this->active);
            app()->forgetInstance(ComposerRegistry::class);
            $this->active->forget();
        }
    }
}
