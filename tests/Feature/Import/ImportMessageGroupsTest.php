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
 * Codes constructed in app/Domain/Import the way the importer leaves them.
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

        $src = (string) file_get_contents($file->getPathname());

        foreach ([
            "/ImportMessage::(?:error|warning)\\(\\s*'([^']+)'/",
            "/new ImportMessage\\(\\s*'([^']+)'/",
            "/->leave\\(\\s*[^,]+,\\s*'([^']+)'/",
            "/leave\\(\\s*'([a-z][a-z0-9.-]+)'/",
        ] as $pattern) {
            if (preg_match_all($pattern, $src, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $code) {
                $codes[$code] = true;
            }
        }
    }

    $conversion = (string) file_get_contents(app_path('Domain/Import/Convert/ConversionReport.php'));

    if (preg_match_all("/'((?:shortcode|block|imported|links|images|gallery|embed)\\.[a-z0-9.-]+)'/", $conversion, $matches) !== false) {
        foreach ($matches[1] as $code) {
            $codes[$code] = true;
        }
    }

    $list = array_keys($codes);
    sort($list);

    return $list;
}

it('maps every code the importer writes to a dedicated group', function (): void {
    $codes = importWrittenCodes();

    expect($codes)->not->toBeEmpty();

    foreach ($codes as $code) {
        expect(ImportMessageGroups::maps($code))->toBeTrue("Missing map for {$code}");
        expect(ImportMessageGroups::for($code)['group'])->not->toBe('Other notes');
    }
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
