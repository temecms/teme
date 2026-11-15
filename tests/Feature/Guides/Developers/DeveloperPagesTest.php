<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Guides\DeveloperGuide;
use Tests\Fixtures\Guides\GuideFile;

/*
 * The shape of every page of the developer handbook, and that no page makes
 * a claim it can't back: a missing file, a command that isn't registered, a
 * class or hook that isn't in the code, or an example block that isn't in
 * its named file.
 */

it('has every page the guide names and no other', function (): void {
    expect(DeveloperGuide::foundPages())->toEqualCanonicalizing(DeveloperGuide::PAGES);
});

it('opens with front matter for title, type, audience, summary and rests_on', function (GuideFile $page): void {
    $front = $page->frontMatter();

    expect($front)->toHaveKeys(['title', 'type', 'audience', 'summary', 'rests_on'])
        ->and(DeveloperGuide::TYPES)->toContain($front['type'])
        ->and(DeveloperGuide::AUDIENCES)->toContain($front['audience'])
        ->and($front['summary'])->toBeString()->not->toBe('')
        ->and($front['rests_on'])->toBeArray()->not->toBeEmpty()
        ->and($page->headingsAt(1))->toBe([$front['title']]);

    if ($front['type'] === 'stub') {
        expect($front)->toHaveKey('lands_with')
            ->and($front['lands_with'])->toBeString()->not->toBe('');
    }
})->with(DeveloperGuide::allPages());

it('rests only on folders and files that exist', function (GuideFile $page): void {
    $missing = [];

    foreach ($page->frontMatter()['rests_on'] as $path) {
        if (! is_string($path) || (! is_file(GuideFile::root().'/'.$path) && ! is_dir(GuideFile::root().'/'.$path))) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([]);
})->with(DeveloperGuide::allPages());

it('links only to files and headings that exist', function (GuideFile $page): void {
    $problems = [];

    foreach ($page->links() as $link) {
        $to = $page->resolve($link['target']);

        if ($to === null) {
            continue;
        }

        $file = GuideFile::root().'/'.$to['path'];

        if (! is_file($file) && ! is_dir($file)) {
            $problems[] = "{$link['target']} leads to {$to['path']}, which isn't there.";

            continue;
        }

        if ($to['anchor'] !== '' && is_file($file) && ! in_array($to['anchor'], (new GuideFile($to['path']))->anchors(), true)) {
            $problems[] = "{$link['target']} leads to a heading that {$to['path']} doesn't have.";
        }
    }

    expect($problems)->toBe([]);
})->with(DeveloperGuide::allPages());

it('names only commands that exist, with options they take', function (GuideFile $page): void {
    $registered = Artisan::all();
    $problems = [];

    foreach (DeveloperGuide::commands($page) as $command) {
        if (! isset($registered[$command['name']])) {
            $problems[] = "{$command['name']} isn't a command.";

            continue;
        }

        $registered[$command['name']]->mergeApplicationDefinition();

        foreach ($command['options'] as $option) {
            if (! $registered[$command['name']]->getDefinition()->hasOption($option)) {
                $problems[] = "{$command['name']} takes no --{$option}.";
            }
        }
    }

    expect($problems)->toBe([]);
})->with(DeveloperGuide::allPages());

it('names only classes and hooks that exist in the code', function (GuideFile $page): void {
    $problems = [];
    $hooks = DeveloperGuide::hookBasenames();

    foreach (DeveloperGuide::codeNames($page) as $name) {
        if (str_contains($name, '\\')) {
            if (! class_exists($name) && ! interface_exists($name)) {
                $problems[] = "{$name} isn't a class or interface.";
            }

            continue;
        }

        if (! in_array($name, $hooks, true)) {
            $problems[] = "{$name} isn't a known hook.";
        }
    }

    expect($problems)->toBe([]);
})->with(DeveloperGuide::allPages());

it('keeps every long code block in the file its first line names', function (GuideFile $page): void {
    $problems = [];

    foreach ($page->fences() as $fence) {
        $body = rtrim($fence['body'], "\n");
        $lines = $body === '' ? [] : explode("\n", $body);

        if (count($lines) <= 3) {
            continue;
        }

        $first = $lines[0];

        if (preg_match('#^//\s+(docs/guides/developers/examples/.+)$#', $first, $found) !== 1
            && preg_match('#^#\s+(docs/guides/developers/examples/.+)$#', $first, $found) !== 1) {
            $problems[] = 'A code block over three lines does not name its file.';

            continue;
        }

        $path = GuideFile::root().'/'.$found[1];

        if (! is_file($path)) {
            $problems[] = "{$found[1]} isn't there.";

            continue;
        }

        $file = str_replace("\r\n", "\n", (string) file_get_contents($path));
        $snippet = implode("\n", array_slice($lines, 1));

        if (! str_contains($file, $snippet)) {
            $problems[] = "The block for {$found[1]} is not in that file.";
        }
    }

    expect($problems)->toBe([]);
})->with(DeveloperGuide::allPages());

it('carries no internal labels in what a reader sees', function (GuideFile $page): void {
    preg_match_all('/\b[IB]-\d+\b|\bD\d+\b|\b[Ss]lice \d|\b[Ss]tudy [A-Z]\b/', $page->body(), $found);

    expect($found[0])->toBe([]);
})->with(DeveloperGuide::allPages());

it('has no pictures, which would go stale where nothing could notice', function (GuideFile $page): void {
    expect($page->text())->not->toContain('![');
})->with(DeveloperGuide::allPages());
