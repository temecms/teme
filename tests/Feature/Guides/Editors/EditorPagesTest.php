<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Guides\AdminWords;
use Tests\Fixtures\Guides\EditorGuide;
use Tests\Fixtures\Guides\GuideFile;

/*
 * The shape of every page of the editors' guide, and that every name it
 * sets in bold is one the admin says, every screen it points at is a page
 * of the admin, and every link leads somewhere.
 */

it('has every page the guide names and no other', function (): void {
    $found = array_map(fn (GuideFile $page): string => substr($page->path, strlen('docs/guides/editors/')), GuideFile::in('editors'));

    expect($found)->toEqualCanonicalizing(EditorGuide::PAGES);
});

it('opens with front matter that names its title, who it is for, and the screens and files it rests on', function (GuideFile $page): void {
    $front = $page->frontMatter();

    expect($front)->toHaveKeys(['title', 'audience', 'kind', 'who', 'screens', 'sources'])
        ->and($front['audience'])->toBe('editors')
        ->and(EditorGuide::KINDS)->toContain($front['kind'])
        ->and($front['who'])->toBeString()->not->toBe('')
        ->and($front['screens'])->toBeArray()
        ->and($front['sources'])->toBeArray()
        ->and($page->headingsAt(1))->toBe([$front['title']]);
})->with(EditorGuide::pages());

it('is task first: every heading of a task page begins with To, If or What, and one begins with To', function (GuideFile $page): void {
    if ($page->frontMatter()['kind'] === 'index') {
        expect($page->headingsAt(2))->not->toBe([]);

        return;
    }

    $headings = $page->headingsAt(2);

    expect(array_values(array_filter($headings, fn (string $heading): bool => preg_match('/^(To|If|What) /', $heading) !== 1)))->toBe([])
        ->and(array_values(array_filter($headings, fn (string $heading): bool => str_starts_with($heading, 'To '))))->not->toBe([]);
})->with(EditorGuide::pages());

it('names only what the admin says, as it says it', function (GuideFile $page): void {
    $sources = (array) $page->frontMatter()['sources'];
    $problems = [];

    foreach ($page->boldSpans() as $span) {
        foreach (preg_split('/\s*›\s*/u', $span) ?: [] as $name) {
            if (! AdminWords::says($name, $sources)) {
                $where = AdminWords::whereSaid($name);
                $problems[] = "**{$name}** isn't said by this page's sources. ".($where === [] ? 'No file says it.' : 'It is said in '.implode(', ', array_slice($where, 0, 3)).'.');
            }
        }
    }

    expect($problems)->toBe([]);
})->with(EditorGuide::pages());

it('rests on files and screens that exist', function (GuideFile $page): void {
    $front = $page->frontMatter();
    $problems = [];

    foreach ((array) $front['sources'] as $source) {
        if (! file_exists(GuideFile::root().'/'.$source)) {
            $problems[] = "{$source} isn't there.";
        }
    }

    foreach ((array) $front['screens'] as $name) {
        $route = Route::getRoutes()->getByName((string) $name);

        if ($route === null || ! in_array('GET', $route->methods(), true)) {
            $problems[] = "{$name} isn't a page of the admin.";
        }
    }

    expect($problems)->toBe([]);
})->with(EditorGuide::pages());

it('links only inside the guide or to the generated reference, and only to files and headings that exist', function (GuideFile $page): void {
    $problems = [];

    foreach ($page->links() as $link) {
        $to = $page->resolve($link['target']);

        if ($to === null) {
            continue;
        }

        if (! str_starts_with($to['path'], 'docs/guides/editors/') && ! str_starts_with($to['path'], 'docs/reference/')) {
            $problems[] = "{$link['target']} leaves the editors' guide for {$to['path']}.";

            continue;
        }

        if (! is_file(GuideFile::root().'/'.$to['path'])) {
            $problems[] = "{$link['target']} leads to {$to['path']}, which isn't there.";

            continue;
        }

        if ($to['anchor'] !== '' && ! in_array($to['anchor'], (new GuideFile($to['path']))->anchors(), true)) {
            $problems[] = "{$link['target']} leads to a heading that {$to['path']} doesn't have.";
        }
    }

    expect($problems)->toBe([]);
})->with(EditorGuide::pages());

it('carries no internal labels and no pictures', function (GuideFile $page): void {
    preg_match_all('/\b[IB]-\d+\b|\bD\d+\b|\b[Ss]lice \d|\b[Ss]tudy [A-Z]\b/', $page->body(), $found);

    expect($found[0])->toBe([])
        ->and($page->text())->not->toContain('![');
})->with(EditorGuide::pages());
