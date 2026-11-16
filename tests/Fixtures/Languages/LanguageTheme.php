<?php

declare(strict_types=1);

namespace Tests\Fixtures\Languages;

use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/**
 * A theme, written to a folder of its own for one test, whose one template
 * prints the three names a page's language fills as JSON (I-3360), with the
 * locale its composer is told, so a test reads what a theme is given; then
 * its footer menu and `<x-teme::languages>`; and a reader of it.
 */
final class LanguageTheme
{
    /**
     * Writes the theme and makes it the active one; returns the folder
     * holding it, which remove() deletes.
     */
    public static function install(): string
    {
        // A theme's folder is named by its code.
        $root = storage_path('framework/testing/language-theme-'.bin2hex(random_bytes(4)));
        $folder = $root.'/languages';
        File::ensureDirectoryExists($folder.'/views/templates');
        File::put($folder.'/teme-theme.json', (string) json_encode([
            'manifest' => 1,
            'code' => 'languages',
            'name' => 'Fixture Languages',
            'version' => '0.1.0',
            'description' => 'A theme that prints its language view data.',
            'requires' => ['teme' => '^0.1'],
            'navigations' => ['footer' => 'Footer'],
            'composers' => [TellsLocale::class],
        ]));
        File::put($folder.'/views/templates/index.blade.php', <<<'BLADE'
            <x-teme::document><main id="main"><pre data-view-data>{{ json_encode([
                'language' => $language,
                'direction' => $direction,
                'alternates' => $alternates,
                'site' => ['language' => $site->language, 'locale' => $site->locale],
                'told' => $toldLocale,
            ]) }}</pre><div data-menu><x-teme::navigation slot="footer" label="Footer" /></div><div data-component><x-teme::languages /></div></main></x-teme::document>
            BLADE);

        config(['teme.theme_paths' => [$folder]]);
        themeActivate('languages');

        return $root;
    }

    public static function remove(string $folder): void
    {
        File::deleteDirectory($folder);
    }

    /**
     * What the page's template printed.
     *
     * @return array{language: array{tag: string, locale: string, name: string, isDefault: bool, home: string}, direction: string, alternates: list<array{tag: string, name: string, url: string, current: bool, isDefault: bool}>, site: array{language: string, locale: string}, told: string}
     */
    public static function read(TestResponse $response): array
    {
        preg_match('#<pre data-view-data>(.*?)</pre>#s', (string) $response->getContent(), $match);

        /** @var array{language: array{tag: string, locale: string, name: string, isDefault: bool, home: string}, direction: string, alternates: list<array{tag: string, name: string, url: string, current: bool, isDefault: bool}>, site: array{language: string, locale: string}, told: string} */
        return json_decode(html_entity_decode($match[1] ?? 'null', ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    }
}
