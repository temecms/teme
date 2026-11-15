<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

// The design system's check (docs/architecture/design-system.md, section 9).
// What the admin held against it when the check arrived is listed in
// design-system-baseline.txt, one `rule<TAB>path<TAB>match` line for each
// place, and the list only shrinks.

/**
 * The baseline's lines.
 *
 * @return list<string>
 */
function dsBaseline(): array
{
    $lines = preg_split('/\R/', (string) file_get_contents(base_path('tests/Architecture/design-system-baseline.txt'))) ?: [];

    return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
}

/**
 * What the scanner finds under a folder holding js/ and css/.
 *
 * @return array{offences: list<array{0: string, 1: string, 2: string}>, unreadable: list<string>, contrast: list<array{0: string, 1: string, 2: string, 3: float|int, 4: float|int}>}
 */
function dsScan(string $root): array
{
    return guardNode('design-system-scan.mjs', ['root' => $root]);
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
function dsSurplus(array $tally, array $than): array
{
    $surplus = [];

    foreach ($tally as $line => $count) {
        for ($extra = $count - ($than[$line] ?? 0); $extra > 0; $extra--) {
            $surplus[] = (string) $line;
        }
    }

    return $surplus;
}

/**
 * Each rule's number of baseline lines: lowered as offences are fixed, never
 * raised (.ai/rules/builders.md: a wider allow-list is a STOP). A rule that
 * reaches zero leaves the list.
 *
 * @return array<string, int>
 */
function dsCounts(): array
{
    return [
        'R1' => 108,
        'R2' => 627,
        'R3' => 139,
        'R5' => 78,
        'R6' => 38,
        'R7' => 72,
        'R8' => 30,
        'R9' => 22,
        'R10' => 13,
        'R14' => 13,
        'R15' => 8,
    ];
}

it('finds nothing against the design system but what was listed when the check arrived', function (): void {
    $scan = dsScan(resource_path());
    $found = array_count_values(array_map(fn (array $offence): string => implode("\t", $offence), $scan['offences']));
    $listed = array_count_values(dsBaseline());
    $new = dsSurplus($found, $listed);
    $gone = dsSurplus($listed, $found);

    expect($scan['unreadable'])->toBe([], "Files the check couldn't read:\n".implode("\n", $scan['unreadable']))
        ->and($new)->toBe([], "New offences against the design system (docs/architecture/design-system.md, section 9). Use its tokens and components. If a file only moved, change its path on its lines in tests/Architecture/design-system-baseline.txt:\n".implode("\n", $new))
        ->and($gone)->toBe([], "These are fixed. Remove that many of their lines from tests/Architecture/design-system-baseline.txt and lower their rule's number in dsCounts():\n".implode("\n", $gone));
});

it('holds each rule to its number, and the rules held at zero to none', function (): void {
    $counts = array_count_values(array_map(fn (string $line): string => explode("\t", $line)[0], dsBaseline()));
    $pinned = dsCounts();
    ksort($counts, SORT_NATURAL);
    ksort($pinned, SORT_NATURAL);

    expect($counts)->toBe($pinned)
        ->and(array_values(array_intersect(array_keys($counts), ['R4', 'R11', 'R12', 'R13'])))->toBe([]);
});

it('computes every contrast pair of the design system, and Ember\'s text on its solid as the document gives it', function (): void {
    $contrast = dsScan(resource_path())['contrast'];

    expect($contrast)->toHaveCount(144)
        ->toContain(['light', '--on-accent', '--accent', 5.61, 4.5])
        ->toContain(['dark', '--on-accent', '--accent', 7.46, 4.5]);
});

it('catches every rule in a planted screen, and leaves the system\'s own files and the generated ones alone', function (): void {
    $root = sys_get_temp_dir().'/teme-design-system-'.Str::random(8);
    File::ensureDirectoryExists("{$root}/js/pages");
    File::ensureDirectoryExists("{$root}/js/components/ui");
    File::ensureDirectoryExists("{$root}/js/routes");
    File::ensureDirectoryExists("{$root}/css");

    File::put("{$root}/js/pages/planted.tsx", <<<'TSX'
        export default function Planted() {
            const ink = '#123456';
            const hue = 'oklch(0.5 0.1 var(--accent-h))';

            return (
                <div className="p-6 max-w-[640px]">
                    <span className="text-[13px] text-sm px-2.5 bg-white rounded shadow-sm z-50 overflow-y-auto outline-none transition-all animate-pulse bg-ember text-muted-foreground" style={{ color: 'white', marginTop: 4 }}>{ink}{hue}</span>
                    <button type="button">Go</button>
                    <p role="note" className="bg-warn-soft p-4">Note</p>
                    <div style={{ '--tile': '160px', left: '4px' }} className="h-(--tile) w-(--nowhere)" />
                </div>
            );
        }
        TSX);
    File::put("{$root}/js/components/ui/raw.tsx", <<<'TSX'
        export function Raw() {
            return <button type="button" className="focus-visible:outline-2 outline-hidden">Raw</button>;
        }
        TSX);
    File::put("{$root}/js/routes/generated.ts", "export const generated = { className: 'text-[99px]' };\n");
    File::put("{$root}/js/broken.tsx", "export const broken = <div>;\n");
    File::put("{$root}/css/extra.css", ".planted {\n    color: #fff;\n    overflow: auto;\n    font-size: 13px;\n    transition: all 200ms ease;\n}\n");

    // The real token block, with the quiet text made unreadable and the accent moved beside the problem colour.
    $tokens = Str::before((string) file_get_contents(resource_path('css/app.css')), '/* @end-tokens */')."/* @end-tokens */\n";
    $planted = (string) preg_replace(['/--ink-3:\s*oklch\(0\.52 0\.004 260\);/', '/--accent-h:\s*42;/'], ['--ink-3: oklch(0.81 0.004 260);', '--accent-h: 25;'], $tokens, 1);
    File::put("{$root}/css/app.css", $planted);

    try {
        $scan = dsScan($root);
    } finally {
        File::deleteDirectory($root);
    }

    $contrast = array_values(array_filter($scan['offences'], fn (array $offence): bool => $offence[0] === 'R12'));
    $rest = array_values(array_filter($scan['offences'], fn (array $offence): bool => $offence[0] !== 'R12'));

    expect(substr_count($planted, '--accent-h: 25;'))->toBe(1)
        ->and(substr_count($planted, '--ink-3: oklch(0.81 0.004 260);'))->toBe(1)
        ->and($rest)->toEqualCanonicalizing([
            ['R1', 'js/pages/planted.tsx', 'max-w-[640px]'],
            ['R10', 'js/pages/planted.tsx', 'outline-none'],
            ['R11', 'js/pages/planted.tsx', 'bg-ember'],
            ['R11', 'js/pages/planted.tsx', 'text-muted-foreground'],
            ['R11', 'js/pages/planted.tsx', 'var(--nowhere)'],
            ['R13', 'js/pages/planted.tsx', 'bg-ember'],
            ['R13', 'js/pages/planted.tsx', 'var(--accent-h)'],
            ['R14', 'css/extra.css', '.planted { transition: all 200ms ease }'],
            ['R14', 'js/pages/planted.tsx', 'animate-pulse'],
            ['R14', 'js/pages/planted.tsx', 'transition-all'],
            ['R15', 'js/pages/planted.tsx', 'style: color'],
            ['R15', 'js/pages/planted.tsx', 'style: marginTop'],
            ['R2', 'css/extra.css', '.planted { font-size: 13px }'],
            ['R2', 'js/pages/planted.tsx', 'text-[13px]'],
            ['R2', 'js/pages/planted.tsx', 'text-sm'],
            ['R3', 'js/pages/planted.tsx', 'px-2.5'],
            ['R4', 'css/extra.css', 'color: #fff'],
            ['R4', 'js/pages/planted.tsx', '#123456'],
            ['R4', 'js/pages/planted.tsx', 'bg-white'],
            ['R4', 'js/pages/planted.tsx', 'oklch(0.5 0.1 var(--accent-h))'],
            ['R4', 'js/pages/planted.tsx', 'style: color: white'],
            ['R5', 'js/pages/planted.tsx', '<button type="button">'],
            ['R6', 'js/pages/planted.tsx', 'rounded'],
            ['R6', 'js/pages/planted.tsx', 'shadow-sm'],
            ['R6', 'js/pages/planted.tsx', 'z-50'],
            ['R7', 'js/pages/planted.tsx', 'frame:max-w-[640px]'],
            ['R7', 'js/pages/planted.tsx', 'frame:p-4'],
            ['R7', 'js/pages/planted.tsx', 'frame:p-6'],
            ['R7', 'js/pages/planted.tsx', 'frame:px-2.5'],
            ['R7', 'js/pages/planted.tsx', 'root:div'],
            ['R8', 'css/extra.css', '.planted { overflow: auto }'],
            ['R8', 'js/pages/planted.tsx', 'overflow-y-auto'],
            ['R9', 'js/pages/planted.tsx', 'classes: bg-warn-soft p-4'],
            ['R9', 'js/pages/planted.tsx', 'role="note"'],
        ])
        ->and($contrast)->toContain(['R12', 'css/app.css', '--accent-h 25 is 7 degrees from danger, needs 20'])
        ->and(array_filter($contrast, fn (array $offence): bool => str_starts_with($offence[2], 'light: --ink-3 on --surface is')))->not->toBeEmpty()
        ->and($scan['unreadable'])->toBe(['js/broken.tsx']);
});
