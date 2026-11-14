<?php

declare(strict_types=1);

use App\Domain\Export\ExportKinds;
use App\Domain\Export\ExportManifest;
use App\Domain\Export\LeftOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Export\ExportAs;
use Tests\Fixtures\Export\SampleSite;

uses(RefreshDatabase::class);

it('writes only the keys each kind documents, and the sample site shows every one of them', function (): void {
    SampleSite::make();

    foreach ((new ExportKinds)->all() as $kind) {
        $documented = array_keys($kind->fields());
        $topLevel = array_values(array_filter($documented, fn (string $key): bool => ! str_contains($key, '.')));
        $seen = [];

        foreach (ExportAs::kindLines($kind, pageSize: 2) as $line) {
            $keys = ExportAs::flatKeys($line);

            expect(array_diff($keys, $documented))->toBe([], "{$kind->name()} writes a key it doesn’t document")
                ->and(array_keys($line))->toBe($topLevel, "{$kind->name()} writes its keys in another order");

            array_push($seen, ...$keys);
        }

        expect(array_values(array_diff($documented, $seen)))->toBe([], "{$kind->name()} documents a key the sample site never shows");
    }
});

it('names each kind once, in lowercase and underscores, with a version, a description and a noun for one and many', function (): void {
    $names = [];

    foreach ((new ExportKinds)->all() as $kind) {
        $names[] = $kind->name();

        expect($kind->name())->toMatch('/^[a-z][a-z_]*$/')
            ->and($kind->version())->toBe(in_array($kind->name(), ['contents', 'terms'], true) ? 2 : 1)
            ->and($kind->description())->toEndWith('.')
            ->and(explode('|', $kind->noun()))->toHaveCount(2)
            ->and(array_keys($kind->fields()))->not->toContain('kind')
            ->and(array_keys($kind->fields()))->not->toContain('version');
    }

    expect(array_unique($names))->toBe($names);
});

it('generates the reference from the kinds, what the manifest holds and what the export leaves out', function (): void {
    $path = storage_path('framework/testing/export-reference.md');

    $this->artisan('teme:docs:export', ['--output' => $path])->assertSuccessful();
    $markdown = (string) file_get_contents($path);
    unlink($path);

    expect($markdown)->toStartWith('# Export format reference')
        ->and($markdown)->toContain('## `contents.ndjson`')
        ->and($markdown)->toContain('| `published.document` | Its body, as Tiptap JSON. |')
        ->and($markdown)->toContain('| `author_email` | The commenter\'s email address, or null. |')
        ->and($markdown)->toContain('| `file.sha256` |')
        ->and($markdown)->toContain('## `manifest.json`')
        ->and($markdown)->toContain('| `'.array_key_first(ExportManifest::FIELDS).'` |')
        ->and($markdown)->toContain('| '.LeftOut::ALL[2]['what'].' | '.LeftOut::ALL[2]['why'].' |');
});
