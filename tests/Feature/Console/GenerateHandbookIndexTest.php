<?php

declare(strict_types=1);

use Tests\Fixtures\Guides\DeveloperGuide;
use Tests\Fixtures\Guides\GuideFile;

it('lists every handbook page once with its summary, in README order', function (): void {
    $path = storage_path('framework/testing/llms.txt');

    $this->artisan('teme:docs:handbook-index', ['--output' => $path])->assertSuccessful();

    $text = (string) file_get_contents($path);

    expect($text)->toContain('# Teme developer handbook')
        ->and(substr_count($text, "\n- ["))->toBe(count(DeveloperGuide::PAGES) - 1);

    foreach (DeveloperGuide::PAGES as $name) {
        if ($name === 'README.md') {
            continue;
        }

        $page = new GuideFile("docs/guides/developers/{$name}");
        $front = $page->frontMatter();

        expect($text)->toContain('- ['.$front['title'].']('.$name.'): '.$front['summary']);
    }

    $positions = [];

    foreach (DeveloperGuide::PAGES as $name) {
        if ($name === 'README.md') {
            continue;
        }

        $title = (new GuideFile("docs/guides/developers/{$name}"))->frontMatter()['title'];
        $positions[] = strpos($text, '- ['.$title.']('.$name.'):');
    }

    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted);
});

it('keeps the committed llms.txt in step with the command', function (): void {
    $path = storage_path('framework/testing/llms-drift.txt');

    $this->artisan('teme:docs:handbook-index', ['--output' => $path])->assertSuccessful();

    expect(file_get_contents($path))->toBe(file_get_contents(base_path('docs/guides/developers/llms.txt')));
});
