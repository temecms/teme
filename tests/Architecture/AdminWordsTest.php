<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

// New admin words go through the catalogue (I-3373): each word a screen shows
// is looked up with __() or __n() from resources/js/lib/i18n.ts. The words the
// screens held when this check arrived are in admin-words-baseline.txt, one
// `path<TAB>word` line for each place a word is written, and the list only shrinks.

/**
 * The baseline's lines.
 *
 * @return list<string>
 */
function awBaseline(): array
{
    $lines = preg_split('/\R/', (string) file_get_contents(base_path('tests/Architecture/admin-words-baseline.txt'))) ?: [];

    return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
}

/**
 * What the scanner finds under a folder of the admin's TypeScript.
 *
 * @return array{words: list<array{0: string, 1: string}>, atLoad: list<array{0: string, 1: int}>, unreadable: list<string>}
 */
function awScan(string $root): array
{
    return guardNode('admin-words-scan.mjs', ['root' => $root]);
}

/**
 * The lines one tally holds more often than another, each repeated as many
 * times as it is over.
 *
 * @param  array<string, int>  $tally
 * @param  array<string, int>  $than
 *
 * @return list<string>
 */
function awSurplus(array $tally, array $than): array
{
    $surplus = [];

    foreach ($tally as $line => $count) {
        for ($extra = $count - ($than[$line] ?? 0); $extra > 0; $extra--) {
            $surplus[] = (string) $line;
        }
    }

    return $surplus;
}

it('finds no word on a screen outside the catalogue but those listed when the check arrived', function (): void {
    $scan = awScan(resource_path('js'));
    $found = array_count_values(array_map(fn (array $word): string => "{$word[0]}\t{$word[1]}", $scan['words']));
    $listed = array_count_values(awBaseline());
    $new = awSurplus($found, $listed);
    $gone = awSurplus($listed, $found);

    expect($scan['unreadable'])->toBe([], "Files the check couldn't read:\n".implode("\n", $scan['unreadable']))
        ->and($new)->toBe([], "Words outside the catalogue, each once for every copy too many. Write each through __() or __n() from '@/lib/i18n'. If a file only moved, change its path on its lines in tests/Architecture/admin-words-baseline.txt:\n".implode("\n", $new))
        ->and($gone)->toBe([], "These words are no longer written there (each line once for every copy gone). Remove that many of their lines from tests/Architecture/admin-words-baseline.txt and lower the count in 'never lets the baseline grow':\n".implode("\n", $gone));
});

it('looks no word up where a module loads', function (): void {
    $atLoad = array_map(fn (array $call): string => "{$call[0]}:{$call[1]}", awScan(resource_path('js'))['atLoad']);

    expect($atLoad)->toBe([], "__() or __n() where a module loads, before the page's words arrive. Call it while drawing:\n".implode("\n", $atLoad));
});

it('never lets the baseline grow', function (): void {
    // Lowered as words leave, never raised (.ai/rules/builders.md: a wider allow-list is a STOP).
    expect(awBaseline())->toHaveCount(1374);
});

it('finds words planted in a new screen, and accepts those through the catalogue', function (): void {
    $root = sys_get_temp_dir().'/teme-admin-words-'.Str::random(8);
    File::ensureDirectoryExists("{$root}/routes/plugins");
    File::put("{$root}/planted.tsx", <<<'TSX'
        export function Planted({ count }: { count: number }) {
            return (
                <section aria-label="Planted region">
                    <h1>Planted heading</h1>
                    <h2>Planted heading</h2>
                    <p title={count > 1 ? 'Many planted' : 'One planted'}>{`Planted ${count}`}</p>
                    <input placeholder={__('Through the catalogue')} />
                    <p>{__n('One thing|:count things', count)}</p>
                </section>
            );
        }

        const columns = [{ label: 'Planted column' }];
        const early = __('Too early');
        toast.success('Planted toast');
        TSX);
    File::put("{$root}/routes/plugins/index.ts", "export const generated = { label: 'Generated' };\n");
    File::put("{$root}/broken.tsx", "export const broken = <div>;\n");

    try {
        $scan = awScan($root);
    } finally {
        File::deleteDirectory($root);
    }

    expect($scan['words'])->toEqualCanonicalizing([
        ['planted.tsx', 'Planted region'],
        ['planted.tsx', 'Planted heading'],
        ['planted.tsx', 'Planted heading'],
        ['planted.tsx', 'Many planted'],
        ['planted.tsx', 'One planted'],
        ['planted.tsx', 'Planted {}'],
        ['planted.tsx', 'Planted column'],
        ['planted.tsx', 'Planted toast'],
    ])
        ->and($scan['atLoad'])->toBe([['planted.tsx', 14]])
        ->and($scan['unreadable'])->toBe(['broken.tsx']);
});
