<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Guides\GuideFile;
use Tests\Fixtures\Guides\OperatorGuide;

/*
 * The shape of every page of the operators' guide, and that no page makes a
 * claim it can't back: a link that leads nowhere, a command or variable
 * that doesn't exist, a service the recipe doesn't run.
 */

it('has every page the guide names and no other', function (): void {
    $found = array_map(fn (GuideFile $page): string => substr($page->path, strlen('docs/guides/operators/')), GuideFile::in('operators'));

    expect($found)->toEqualCanonicalizing(OperatorGuide::PAGES);
});

it('opens with front matter that names its title, its audience and how far it is proved', function (GuideFile $page): void {
    $front = $page->frontMatter();

    expect($front)->toHaveKeys(['title', 'audience', 'proved', 'written_for'])
        ->and($front['audience'])->toBe('operators')
        ->and(OperatorGuide::PROOF)->toContain($front['proved'])
        ->and($front['written_for'])->toBeString()->not->toBe('')
        ->and($page->headingsAt(1))->toBe([$front['title']]);
})->with(OperatorGuide::pages());

it('says in a line of its own that it is not proved yet, when it is not', function (GuideFile $page): void {
    $says = str_contains($page->body(), "\n> Not proved yet:");

    expect($says)->toBe($page->frontMatter()['proved'] === 'unproved');
})->with(OperatorGuide::pages());

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
})->with(OperatorGuide::pages());

it('carries no internal labels in what a reader sees', function (GuideFile $page): void {
    preg_match_all('/\b[IB]-\d+\b|\bD\d+\b|\b[Ss]lice \d|\b[Ss]tudy [A-Z]\b/', $page->body(), $found);

    expect($found[0])->toBe([]);
})->with(OperatorGuide::pages());

it('has no pictures, which would go stale where nothing could notice', function (GuideFile $page): void {
    expect($page->text())->not->toContain('![');
})->with(OperatorGuide::pages());

it('sets every command in code, each option beside its command', function (GuideFile $page): void {
    $alone = array_values(array_filter($page->codeSpans(), fn (string $span): bool => str_starts_with($span, '--')));

    expect(preg_match('/\bteme:[a-z]/', $page->prose()))->toBe(0)
        ->and($alone)->toBe([]);
})->with(OperatorGuide::pages());

it('names only commands that exist, with options they take', function (GuideFile $page): void {
    $registered = Artisan::all();
    $problems = [];

    foreach (OperatorGuide::commands($page) as $command) {
        if (in_array($command['name'], OperatorGuide::ARRIVING, true)) {
            continue;
        }

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
})->with(OperatorGuide::pages());

it('names only variables that Teme, the image, the recipe or a program it runs reads', function (GuideFile $page): void {
    expect(array_values(array_diff(OperatorGuide::variables($page), OperatorGuide::known())))->toBe([]);
})->with(OperatorGuide::pages());

it('names only services and profiles that the recipe defines', function (GuideFile $page): void {
    $targets = OperatorGuide::composeTargets($page);

    expect(array_values(array_diff($targets['services'], OperatorGuide::services())))->toBe([])
        ->and(array_values(array_diff($targets['profiles'], OperatorGuide::profiles())))->toBe([]);
})->with(OperatorGuide::pages());

it('names only files of the repository that are there', function (GuideFile $page): void {
    $missing = array_values(array_filter(OperatorGuide::paths($page), fn (string $path): bool => ! file_exists(GuideFile::root().'/'.$path)));

    expect($missing)->toBe([]);
})->with(OperatorGuide::pages());

it('lists every other page in its table with how far the page says it is proved', function (): void {
    preg_match_all('/^\|\s*\[[^\]]+\]\(([^)#]+)\)\s*\|\s*([^|]+?)\s*\|/m', OperatorGuide::page('README.md')->body(), $rows, PREG_SET_ORDER);

    $listed = [];

    foreach ($rows as $row) {
        $listed[$row[1]] = $row[2];
    }

    $said = [];

    foreach (OperatorGuide::PAGES as $name) {
        if ($name !== 'README.md') {
            $said[$name] = OperatorGuide::page($name)->frontMatter()['proved'];
        }
    }

    ksort($listed);
    ksort($said);

    expect($listed)->toBe($said);
});
