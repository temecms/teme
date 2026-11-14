<?php

declare(strict_types=1);

use App\Domain\Themes\Reference\ViewDataCatalogue;
use App\Domain\Themes\Reference\ViewDataPin;
use App\Domain\Themes\ViewIntent;

it('keeps the committed reference and its pin in step with the code', function (): void {
    $dir = storage_path('framework/testing/theme-reference');
    $this->artisan('teme:docs:theme', ['--output' => $dir])->assertSuccessful();

    expect(file_get_contents($dir.'/theme.md'))->toBe(file_get_contents(base_path('docs/reference/theme.md')))
        ->and(file_get_contents($dir.'/theme-view-data.json'))->toBe(file_get_contents(base_path('docs/reference/theme-view-data.json')));
});

it('writes the same bytes every time it runs', function (): void {
    $first = storage_path('framework/testing/theme-reference-a');
    $second = storage_path('framework/testing/theme-reference-b');
    $this->artisan('teme:docs:theme', ['--output' => $first])->assertSuccessful();
    $this->artisan('teme:docs:theme', ['--output' => $second])->assertSuccessful();

    foreach (['theme.md', 'theme-view-data.json'] as $file) {
        $text = (string) file_get_contents($first.'/'.$file);

        expect($text)->toBe((string) file_get_contents($second.'/'.$file))
            ->and($text)->not->toContain("\r")
            ->and($text)->not->toContain(base_path())
            ->and($text)->toEndWith("\n")
            ->and($text)->not->toEndWith("\n\n");
    }
});

it('names in the changelog every view value renamed, retyped or removed', function (): void {
    $pin = json_decode((string) file_get_contents(base_path('docs/reference/theme-view-data.json')), true, 512, JSON_THROW_ON_ERROR);

    expect(ViewDataPin::unnoted($pin['entries'], (string) file_get_contents(base_path('CHANGELOG.md'))))->toBe([]);
});

it('gives every kind of page its model, and every kind has one', function (): void {
    expect(array_keys(ViewDataCatalogue::PAGE_MODELS))->toEqualCanonicalizing(ViewIntent::KINDS);
});

it('types every list and describes every value', function (): void {
    $entries = app(ViewDataCatalogue::class)->entries();
    $bare = array_keys(array_filter($entries, fn (array $e): bool => in_array($e['type'], ['array', '?array'], true)));
    $undescribed = array_keys(array_filter($entries, fn (array $e): bool => $e['description'] === ''));

    expect($bare)->toBe([])
        ->and($undescribed)->toBe([]);
});

it('lists the values a template reads, from the page to its smallest part', function (): void {
    $entries = array_map(fn (array $e): string => $e['type'], app(ViewDataCatalogue::class)->entries());

    expect($entries)->toMatchArray([
        'ArticleView::$title' => 'string',
        'ArticleView::$authors' => 'list<Person>',
        'Person::$name' => 'string',
        'ListingView::$items' => 'list<ListingItem>',
        'TermLink::$url' => 'string',
        '$site' => 'SiteView',
        'kind error' => 'ErrorView',
        '<x-teme::image> id' => 'string',
        'Pagination::pages()' => '(): list<array{number: int|null, url: string|null, current: bool}>',
        'ArticleView::$related' => 'list<array{item: ListingItem, reason: string}>',
    ]);

    foreach ($entries as $name => $type) {
        preg_match_all('/\b[A-Z][A-Za-z0-9_]*\b/', $type, $words);

        foreach ($words[0] as $word) {
            if (class_exists('App\Domain\Site\ViewModels\\'.$word)) {
                continue;
            }

            $outside = (class_exists($word) || interface_exists($word)) && ! (new ReflectionClass($word))->isInternal();

            expect($outside)->toBeFalse("{$name} names {$word}");
        }

        // toContain() takes needles only, so the message rides on toBeFalse().
        expect(str_contains($type, '\\'))->toBeFalse("{$name} names a class by its namespace");
    }
});

it('writes the reference’s sections', function (): void {
    $dir = storage_path('framework/testing/theme-reference');
    $this->artisan('teme:docs:theme', ['--output' => $dir])->assertSuccessful();
    $text = (string) file_get_contents($dir.'/theme.md');

    expect($text)->toStartWith('# Theme reference')
        ->toContain('## The manifest')
        ->toContain('## Kinds of page')
        ->toContain('## What every template gets')
        ->toContain('## The page’s values')
        ->toContain('## Field values')
        ->toContain('## Components')
        ->toContain('| `requires` | object | yes |')
        ->toContain('| `single` | `ArticleView` | `single-post-full-width`, `single-post`, `single` |')
        ->toContain('No attributes.');
});

it('prints the field-value table, one row a field type, and pins each value', function (): void {
    $dir = storage_path('framework/testing/theme-reference');
    $this->artisan('teme:docs:theme', ['--output' => $dir])->assertSuccessful();
    $text = (string) file_get_contents($dir.'/theme.md');
    $entries = app(ViewDataCatalogue::class)->entries();

    expect($text)->toContain('## Field values')
        ->and(strpos($text, '## The page’s values'))->toBeLessThan((int) strpos($text, '## Field values'))
        ->and(strpos($text, '## Field values'))->toBeLessThan((int) strpos($text, '## Components'))
        ->and($text)->toMatch('/^\| `date` \| `DateTimeImmutable/m')
        ->and($text)->toMatch('/^\| `entry` \|/m')
        ->and($entries['field date']['type'] ?? null)->toBe('DateTimeImmutable|null')
        ->and($entries['field boolean']['type'] ?? null)->toBe('bool')
        ->and($entries['field date']['description'] ?? '')->not->toBe('')
        ->and($entries['field boolean']['description'] ?? '')->not->toBe('');
});
