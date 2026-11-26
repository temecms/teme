<?php

/**
 * Every code the importer writes has a plain group name, and unknown codes
 * fall back to “Other notes” (issue 699 follow-up).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Import\Queries\ImportMessageGroups;
use App\Domain\Import\Queries\ImportScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

/**
 * Strings in app/Domain/Import that look like a message code but aren't one:
 * validation keys, config keys, SQL aliases, route names and note keys.
 */
const IMPORT_NOT_CODES = [
    'address.max', 'address.required', 'app.key', 'app.url', 'bytes.max', 'bytes.min', 'c.id', 'c.title',
    'contents.id', 'contents.status', 'contents.title', 'contents.type', 'contents.visibility', 'embeds.providers',
    'figcaption.blocks-gallery-caption', 'import.page-parents', 'imports.run', 'languages.default-prefixed',
    'languages.from-content', 'm.facts', 'm.id', 'm.status', 'media.id', 'media.kind', 'name.max', 'name.required',
    'row.list', 'size.min', 'teme.import.blocks', 'teme.import.phases', 'teme.import.shortcodes', 'terms.id',
    'terms.slug', 'terms.taxonomy', 'tools.importer.show', 'x.level',
];

/**
 * Every string in app/Domain/Import shaped like a message code (lower-case
 * words joined by dots and dashes), however it is written: a constant, a
 * returned tuple, a ConversionReport count or a message constructor.
 *
 * @return list<string>
 */
function importWrittenCodes(): array
{
    $codes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain/Import'))) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/'([a-z][a-z0-9-]*\\.[a-z][a-z0-9-]*(?:\\.[a-z0-9-]+)?)'/", (string) file_get_contents($file->getPathname()), $matches);

        foreach ($matches[1] as $code) {
            if (! in_array($code, IMPORT_NOT_CODES, true)) {
                $codes[$code] = true;
            }
        }
    }

    $list = array_keys($codes);
    sort($list);

    return $list;
}

/**
 * Every code the map names.
 *
 * @return array<string, string> The plain group name for each code.
 */
function importMappedGroups(): array
{
    $found = [];

    preg_match_all("/^        '([a-z][^']+)' => \\['group' => '((?:[^'\\\\]|\\\\.)*)'/m", (string) file_get_contents(app_path('Domain/Import/Queries/ImportMessageGroups.php')), $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $found[$match[1]] = stripslashes($match[2]);
    }

    return $found;
}

it('maps every code the importer writes to a dedicated group', function (): void {
    $codes = importWrittenCodes();

    expect($codes)->not->toBeEmpty()
        ->and($codes)->toContain('image.missing', 'attachment.alt-shortened', 'page.slug-suffixed', 'setting.front-page-empty', 'video.tracks');

    foreach ($codes as $code) {
        expect(ImportMessageGroups::maps($code))->toBeTrue("Missing map for {$code}");
        expect(ImportMessageGroups::for($code)['group'])->not->toBe('Other notes');
    }
});

it('has no entry for a code the importer no longer writes', function (): void {
    $written = importWrittenCodes();

    foreach (array_keys(importMappedGroups()) as $code) {
        expect($written)->toContain($code);
    }
});

it('gives each code its own plain name, shared only by codes that mean the same thing', function (): void {
    $shared = [
        'Addresses taken here' => ['old-address.taken', 'post.address-taken', 'post.old-addresses-taken'],
        'Menus not placed' => ['menu.not-placed', 'menus.unplaced'],
    ];

    $byName = [];

    foreach (importMappedGroups() as $code => $name) {
        $byName[$name][] = $code;
    }

    expect(importMappedGroups())->not->toBeEmpty();

    foreach ($byName as $name => $codes) {
        if (count($codes) > 1) {
            expect($shared[$name] ?? [])->toEqualCanonicalizing($codes, "“{$name}” is shared by codes that aren't listed as meaning the same");
        }
    }

    // “Failed” is the report’s error group, and “Other notes” the fallback: no code may borrow either.
    expect(array_keys($byName))->not->toContain('Failed', 'Other notes');
});

it('names no group from the last word of its code', function (): void {
    $bare = ['Refused', 'Kept here', 'Too deep', 'Seo not kept', 'Failed', 'Other host', 'Changed here', 'Deleted here', 'Unexpected', 'Skipped', 'Left', 'Gone', 'Object', 'Query'];

    expect(array_values(array_intersect(importMappedGroups(), $bare)))->toBe([]);
});

it('falls back to Other notes for an unknown code, keeping the message’s own words', function (): void {
    expect(ImportMessageGroups::for('zz.not-a-real-code'))->toBe([
        'group' => 'Other notes',
        'sentence' => null,
    ]);
});

it('names the three addresses-taken codes the same way', function (): void {
    foreach (['post.old-addresses-taken', 'old-address.taken', 'post.address-taken'] as $code) {
        expect(ImportMessageGroups::for($code)['group'])->toBe('Addresses taken here');
    }
});

it('folds codes that share a plain name into one group with summed counts', function (): void {
    $source = ImportRecords::source();
    $run = ImportRecords::run($source, ['state' => 'finished', 'phase' => 'check', 'ended_at' => now()]);
    ImportRecords::message($run, null, 'menu.not-placed', 'A menu isn’t shown anywhere yet.', 'warning', 1);
    ImportRecords::message($run, null, 'menu.not-placed', 'Another menu isn’t shown anywhere yet.', 'warning', 2);
    ImportRecords::message($run, null, 'menus.unplaced', 'Menus came across; place them.', 'warning', 3);

    $messages = collect(app(ImportScreen::class)->report($run)['messages']);
    $menus = $messages->firstWhere('group', 'Menus not placed');

    expect($menus)->not->toBeNull()
        ->and($menus['count'])->toBe(3)
        ->and($messages->where('group', 'Menus not placed')->count())->toBe(1);
});
