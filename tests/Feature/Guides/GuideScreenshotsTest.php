<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Guides\GuideFile;
use Tests\Fixtures\Guides\GuideScreens;
use Tests\Support\DemoSite;

/*
 * Every screen a guide points at is a page of the admin, and has its pictures
 * in docs/screenshots/guides, or is left out for a reason said in plain words.
 */

it('points only at screens that are pages of the admin', function (): void {
    $problems = [];

    foreach (GuideScreens::named() as $name) {
        $route = Route::getRoutes()->getByName($name);

        if ($route === null || ! in_array('GET', $route->methods(), true)) {
            $problems[] = "{$name} isn't a page of the admin.";
        }
    }

    expect($problems)->toBe([]);
});

it('has a picture in each scheme for every screen, or a reason it has none', function (): void {
    $index = GuideScreens::index();
    $problems = [];

    foreach (GuideScreens::named() as $name) {
        if (array_key_exists($name, GuideScreens::SKIPPED)) {
            expect(GuideScreens::SKIPPED[$name])->not->toBe('')
                ->and($index)->not->toHaveKey($name);

            continue;
        }

        if (! isset($index[$name])) {
            $problems[] = "{$name} has no picture in index.json and no reason it has none.";

            continue;
        }

        foreach (['light' => "{$name}.png", 'dark' => "{$name}-dark.png"] as $scheme => $file) {
            if (($index[$name][$scheme] ?? null) !== $file || ! is_file(GuideFile::root().'/'.GuideScreens::FOLDER.'/'.$file)) {
                $problems[] = "{$name}'s {$scheme} picture {$file} isn't there.";
            }
        }

        if (trim((string) ($index[$name]['label'] ?? '')) === '') {
            $problems[] = "{$name} has no label.";
        }
    }

    expect($problems)->toBe([]);
});

it('lists no screen that no guide points at, in route order, with plain line endings', function (): void {
    $text = (string) @file_get_contents(GuideFile::root().'/'.GuideScreens::FOLDER.'/index.json');
    $index = GuideScreens::index();

    expect(array_keys($index))->toBe(array_values(array_intersect(GuideScreens::named(), array_keys($index))))
        ->and($text)->not->toContain("\r")
        ->and(str_ends_with($text, "}\n"))->toBeTrue();
});

it('never skips a screen no guide names', function (): void {
    expect(array_diff(array_keys(GuideScreens::SKIPPED), GuideScreens::named()))->toBe([]);
});

it('captions every screen by its name, never by the demo\'s data', function (): void {
    $demo = ['Rowan', 'Hale', 'Clara', 'Benning', 'Theo', 'Marsh', 'Northwind'];
    $articles = (new ReflectionClass(DemoSite::class))->getReflectionConstant('ARTICLES')?->getValue() ?? [];

    foreach ($articles as $article) {
        $demo[] = $article['title'];
    }

    $problems = [];

    foreach (GuideScreens::index() as $name => $entry) {
        if (isset(GuideScreens::LABELS[$name])) {
            if ($entry['label'] !== GuideScreens::LABELS[$name]) {
                $problems[] = "{$name} is captioned '{$entry['label']}', not '".GuideScreens::LABELS[$name]."'.";
            }

            continue;
        }

        foreach ($demo as $word) {
            if (str_contains($entry['label'], $word)) {
                $problems[] = "{$name}'s caption '{$entry['label']}' holds the demo's {$word}; give it a caption in GuideScreens::LABELS.";
            }
        }
    }

    expect($problems)->toBe([])
        ->and(array_diff(array_keys(GuideScreens::LABELS), array_keys(GuideScreens::index())))->toBe([]);
});
