<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Tests\Fixtures\Languages\ReaderWords;

/*
 * The reader words Teme ships (I-3360): every key a reader can meet, in each
 * shipped language, core's in lang/ and the default theme's in its own
 * lang/, each translation keeping its key's placeholders and plural forms.
 * Laravel's own words in lang/ come from laravel-lang and are left to it.
 */

/**
 * One shipped word file, decoded.
 *
 * @return array<string, string>
 */
function rwFile(string $source, string $locale): array
{
    $path = base_path(ReaderWords::SOURCES[$source]['words']."/{$locale}.json");

    expect(is_file($path))->toBeTrue("{$path} is missing");

    /** @var array<string, string> */
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The keys of Laravel's own words for one locale, as laravel-lang publishes
 * them: the only keys besides Teme's a core file may hold.
 *
 * @return list<string>
 */
function rwFramework(string $locale): array
{
    $keys = [];

    foreach (glob(base_path("vendor/laravel-lang/lang/locales/{$locale}/json.json")) ?: [] as $file) {
        $keys = [...$keys, ...array_keys((array) json_decode((string) file_get_contents($file), true))];
    }

    return $keys;
}

/**
 * The placeholders a word holds, sorted.
 *
 * @return list<string>
 */
function rwPlaceholders(string $word): array
{
    preg_match_all('/:[a-z_]+/i', $word, $matches);
    $found = array_values(array_unique($matches[0]));
    sort($found);

    return $found;
}

it('has every reader-facing key in each shipped language', function (string $source, string $locale): void {
    $missing = array_values(array_diff(ReaderWords::keys($source), array_keys(rwFile($source, $locale))));

    expect($missing)->toBe([]);
})->with(array_keys(ReaderWords::SOURCES))->with(ReaderWords::LOCALES);

it('has no key in a shipped file that no reader-facing source uses', function (string $source, string $locale): void {
    $allowed = [...ReaderWords::keys($source), ...($source === 'core' ? rwFramework($locale) : [])];

    expect(array_values(array_diff(array_keys(rwFile($source, $locale)), $allowed)))->toBe([]);
})->with(array_keys(ReaderWords::SOURCES))->with(ReaderWords::LOCALES);

it('keeps each translation\'s placeholders those of its key', function (string $source, string $locale): void {
    $wrong = [];

    foreach (rwFile($source, $locale) as $key => $word) {
        if (in_array($key, ReaderWords::keys($source), true) && rwPlaceholders($key) !== rwPlaceholders($word)) {
            $wrong[] = $key;
        }
    }

    expect($wrong)->toBe([]);
})->with(array_keys(ReaderWords::SOURCES))->with(ReaderWords::LOCALES);

it('keeps a plural key\'s forms in each translation', function (string $source, string $locale): void {
    $words = rwFile($source, $locale);

    foreach (ReaderWords::keys($source) as $key) {
        if (str_contains($key, '|')) {
            expect(substr_count($words[$key], '|'))->toBe(substr_count($key, '|'), $key);
        }
    }
})->with(array_keys(ReaderWords::SOURCES))->with(ReaderWords::LOCALES);

it('never translates a word to nothing', function (string $source, string $locale): void {
    expect(array_keys(array_filter(rwFile($source, $locale), fn (string $word): bool => trim($word) === '')))->toBe([]);
})->with(array_keys(ReaderWords::SOURCES))->with(ReaderWords::LOCALES);

it('leaves admin words out of the reader files', function (): void {
    $reader = [...ReaderWords::keys('core'), ...ReaderWords::keys('theme')];
    $admin = [];

    foreach ([base_path('app/Http/Admin'), base_path('resources/js')] as $folder) {
        foreach (Finder::create()->files()->in($folder)->name(['*.php', '*.ts', '*.tsx']) as $file) {
            $admin = [...$admin, ...ReaderWords::keysIn($file->getContents())];
        }
    }

    $adminOnly = array_diff(array_unique($admin), $reader);

    foreach (ReaderWords::LOCALES as $locale) {
        $framework = rwFramework($locale);

        expect(array_values(array_diff(array_intersect(array_keys(rwFile('core', $locale)), $adminOnly), $framework)))->toBe([])
            ->and(array_values(array_intersect(array_keys(rwFile('theme', $locale)), $adminOnly)))->toBe([]);
    }
});

it('keeps Teme\'s own words when laravel-lang adds and updates a language', function (): void {
    $scratch = sys_get_temp_dir().'/reader-words-'.bin2hex(random_bytes(4));
    File::copyDirectory(base_path('lang'), $scratch);
    $before = rwFile('core', 'fr');
    app()->useLangPath($scratch);

    try {
        Artisan::call('lang:add', ['locales' => ['fr']]);
        Artisan::call('lang:update');

        /** @var array<string, string> $after */
        $after = json_decode((string) file_get_contents($scratch.'/fr.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach (ReaderWords::keys('core') as $key) {
            expect($after[$key] ?? null)->toBe($before[$key], $key);
        }
    } finally {
        app()->useLangPath(base_path('lang'));
        File::deleteDirectory($scratch);
    }
});
