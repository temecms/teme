<?php

declare(strict_types=1);

// uiCss() and uiTsxFiles() live in tests/Pest.php. Pest.php already binds
// every Feature test to TestCase, so this file does not call uses().

/**
 * The custom properties set by the top-level rules of one selector in
 * app.css (`:root` or `.dark`), the rules inside at-rules left out.
 *
 * @return array<string, string>
 */
function dtTokens(string $selector): array
{
    preg_match_all('/^'.preg_quote($selector, '/').' \{(.*?)^\}/ms', uiCss(), $blocks);
    $tokens = [];

    foreach ($blocks[1] as $body) {
        preg_match_all('/^\s*(--[\w-]+):\s*(.*?);\s*$/ms', $body, $declarations, PREG_SET_ORDER);

        foreach ($declarations as [, $name, $value]) {
            $tokens[$name] = trim((string) preg_replace('/\s+/', ' ', $value));
        }
    }

    return $tokens;
}

/**
 * The body of one at-rule block of app.css, such as a media query.
 */
function dtAtRule(string $prelude): string
{
    preg_match('/^'.preg_quote($prelude, '/').' \{(.*?)^\}/ms', uiCss(), $block);

    return $block[1] ?? '';
}

it('sets every token of the design system in light', function (string $token): void {
    expect(dtTokens(':root'))->toHaveKey($token);
})->with([
    '--page', '--chrome', '--surface', '--surface-2', '--surface-3', '--surface-4', '--line', '--edge',
    '--ink', '--ink-2', '--ink-3', '--neutral-dot', '--scrim', '--scroll-thumb', '--scroll-thumb-hover', '--soft-edge',
    '--img-outline', '--photo-scrim', '--on-photo', '--paper',
    '--accent-h', '--accent-c', '--accent-l', '--accent', '--accent-hover', '--on-accent', '--accent-ink',
    '--accent-soft', '--accent-soft-2', '--on-accent-soft', '--ring', '--chart-1', '--chart-2',
    '--ok', '--ok-soft', '--ok-ink', '--info', '--info-soft', '--info-ink', '--warn', '--warn-soft', '--warn-ink',
    '--danger', '--danger-soft', '--danger-ink', '--danger-hover', '--on-danger', '--ai', '--ai-soft', '--ai-ink',
    '--shadow-raised', '--shadow-float', '--shadow-modal',
    '--ctl-h', '--ctl-h-sm', '--ctl-h-xs', '--row-h', '--top-h', '--side-w', '--side-w-rail', '--rail-w', '--measure',
    '--page-max', '--form-max', '--detail-w', '--dialog-sm', '--dialog-md', '--dialog-lg', '--dialog-xl-w', '--dialog-xl-h', '--sheet-w',
    '--ease', '--dur-1', '--dur-2', '--dur-3', '--press',
    '--z-sticky', '--z-toolbar', '--z-workspace', '--z-overlay', '--z-float', '--z-tooltip', '--z-toast',
]);

it('sets again in dark every token whose dark value differs', function (string $token): void {
    expect(dtTokens('.dark'))->toHaveKey($token);
})->with([
    '--page', '--chrome', '--surface', '--surface-2', '--surface-3', '--surface-4', '--line', '--edge',
    '--ink', '--ink-2', '--ink-3', '--neutral-dot', '--scrim', '--scroll-thumb', '--scroll-thumb-hover', '--img-outline',
    '--accent-l', '--accent', '--accent-hover', '--accent-ink', '--accent-soft', '--accent-soft-2', '--on-accent-soft', '--ring', '--chart-2',
    '--ok', '--ok-soft', '--ok-ink', '--info', '--info-soft', '--info-ink', '--warn', '--warn-soft', '--warn-ink',
    '--danger', '--danger-soft', '--danger-ink', '--danger-hover', '--on-danger', '--ai', '--ai-soft', '--ai-ink',
    '--shadow-raised', '--shadow-float', '--shadow-modal',
]);

it('makes Ember the accent and computes every accent token from its pair', function (): void {
    $light = dtTokens(':root');

    expect($light['--accent-h'])->toBe('42')
        ->and($light['--accent-c'])->toBe('0.16')
        ->and($light['--accent-l'])->toBe('0.52')
        ->and(dtTokens('.dark')['--accent-l'])->toBe('0.74');

    foreach (['--accent', '--accent-hover', '--on-accent', '--accent-ink', '--accent-soft', '--accent-soft-2', '--on-accent-soft', '--ring', '--chart-1', '--chart-2'] as $token) {
        expect($light[$token])->toMatch('/var\(--accent(-[hcl])?\)/', "{$token} is not computed from the accent");
    }
});

it('keeps the neutral ramp all but colourless, and the page without colour at all', function (string $selector): void {
    $tokens = dtTokens($selector);

    foreach (['--page', '--chrome', '--surface', '--surface-2', '--surface-3', '--surface-4', '--line', '--edge', '--ink', '--ink-2', '--ink-3', '--neutral-dot'] as $token) {
        expect(preg_match('/^oklch\(\s*[\d.]+\s+([\d.]+)\s+/', $tokens[$token], $match))->toBe(1, $token)
            ->and((float) $match[1])->toBeLessThanOrEqual(0.004, $token);
    }

    if ($selector === ':root') {
        expect($tokens['--page'])->toBe('oklch(0.9875 0 0)');
    }
})->with([':root', '.dark']);

it('follows the computer\'s more contrast with token values only, for light and dark', function (): void {
    $block = dtAtRule('@media (prefers-contrast: more)');

    preg_match_all('/([\w-]+)\s*:\s*[^;{}]+;/', $block, $declarations);

    expect($block)->not->toBe('')
        ->and($declarations[1])->not->toBeEmpty();

    foreach ($declarations[1] as $property) {
        expect($property)->toStartWith('--');
    }

    foreach (['--ink-2', '--ink-3', '--edge', '--line', '--soft-edge'] as $token) {
        expect(substr_count($block, "{$token}:"))->toBe(2, $token);
    }
});

it('shortens every movement under reduced motion, and keeps the spinners turning', function (): void {
    $block = dtAtRule('@media (prefers-reduced-motion: reduce)');

    expect($block)->toContain('--dur-1: 1ms;')
        ->toContain('--dur-2: 1ms;')
        ->toContain('--dur-3: 1ms;')
        ->toContain('--press: 1;')
        ->toContain('.animate-spin');
});

it('keeps the tokens between the markers the check reads', function (): void {
    $css = uiCss();

    expect(strpos($css, '/* @tokens */'))->toBeInt()
        ->and(strpos($css, '/* @tokens */'))->toBeLessThan(strpos($css, '/* @accent */'))
        ->and(strpos($css, '/* @accent */'))->toBeLessThan(strpos($css, '/* @end-accent */'))
        ->and(strpos($css, '/* @end-accent */'))->toBeLessThan(strpos($css, '/* @end-tokens */'));
});

it('loads no font from a font service', function (): void {
    $page = file_get_contents(resource_path('views/app.blade.php'));

    expect($page)->not->toContain('fonts.googleapis')
        ->and($page)->not->toContain('fonts.bunny')
        ->and($page)->not->toContain('fonts.gstatic');
});

it('keeps the system stack behind every family', function (): void {
    foreach (['--font-sans', '--font-serif', '--font-mono'] as $family) {
        preg_match("/{$family}:\s*([^;]+);/", uiCss(), $match);
        expect($match[1])->toMatch('/(system-ui|Georgia|ui-monospace)/');
    }
});

it('has no looks and no contrast setting of its own anywhere (I-3404)', function (): void {
    expect(uiCss())->not->toContain('data-look')->not->toContain('data-contrast')
        ->and((string) file_get_contents(resource_path('views/app.blade.php')))->not->toContain('data-look')->not->toContain('data-contrast')
        ->and(uiComponent('lib/preferences.ts'))->not->toContain('applyLook')->not->toContain('chooseLook');

    foreach (uiTsxFiles() as $file) {
        expect(uiComponent($file))->not->toContain('data-look')->not->toContain('data-contrast')->not->toContain('appearance.look')->not->toContain('appearance.high_contrast');
    }
});
