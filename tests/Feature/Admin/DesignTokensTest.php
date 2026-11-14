<?php

declare(strict_types=1);

// uiCss() lives in tests/Pest.php. Pest.php already binds every Feature
// test to TestCase, so this file does not call uses().

it('defines every token the design system names', function (string $token): void {
    expect(uiCss())->toContain("{$token}:");
})->with([
    '--background', '--foreground', '--card', '--popover', '--muted', '--muted-foreground',
    '--border', '--input', '--ring', '--primary', '--primary-foreground',
    '--success', '--success-muted', '--warning', '--warning-muted',
    '--destructive', '--destructive-muted', '--link', '--selected',
    '--radius', '--radius-sm', '--radius-lg', '--shadow', '--sidebar-muted',
]);

it('gives destructive a foreground that is not itself', function (): void {
    preg_match('/--destructive:\s*([^;]+);/', uiCss(), $destructive);
    preg_match('/--destructive-foreground:\s*([^;]+);/', uiCss(), $foreground);

    expect(trim($foreground[1]))->not->toBe(trim($destructive[1]));
});

it('gives dark mode a surface that is not the canvas', function (): void {
    $dark = substr(uiCss(), strpos(uiCss(), '.dark {'));

    preg_match('/--background:\s*([^;]+);/', $dark, $background);
    preg_match('/--card:\s*([^;]+);/', $dark, $card);

    expect(trim($card[1]))->not->toBe(trim($background[1]));
});

it('sets the accent to the value the owner chose', function (): void {
    expect(uiCss())->toMatch('/--primary:\s*#0a6273;/i');
});

it('keeps the neutral ramp achromatic', function (): void {
    // Every oklch() in the ramp must have zero chroma. The accent and the
    // state colours are hex, so they are not caught by this. The --chart-*
    // tokens are skipped: charts need hues a reader can tell apart.
    $css = preg_replace('/--chart-[\w-]+:[^;]*;/', '', uiCss());

    preg_match_all('/oklch\(\s*[\d.]+\s+([\d.]+)/', $css, $matches);

    foreach ($matches[1] as $chroma) {
        expect((float) $chroma)->toBe(0.0);
    }
});

it('sets the control radius to 4px', function (): void {
    expect(uiCss())->toMatch('/--radius:\s*0\.25rem;/');
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

it('makes Dark chrome the default look', function (): void {
    $root = substr(uiCss(), strpos(uiCss(), ':root {'), strpos(uiCss(), '}', strpos(uiCss(), ':root {')) - strpos(uiCss(), ':root {'));

    expect($root)->toMatch('/--sidebar:\s*#1d1d1f;/i');
});

it('keeps every look to token values, so no component knows which look is on', function (): void {
    // uiTsxFiles() walks resources/js with a RecursiveDirectoryIterator;
    // glob() with ** does not recurse in PHP.
    $files = uiTsxFiles();

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(file_get_contents(resource_path("js/{$file}")))->not->toContain('data-look')->not->toContain('data-contrast');
    }

    preg_match_all('/\[data-(?:look|contrast)[^\]]*\][^{]*\{([^}]*)\}/', uiCss(), $blocks);

    foreach ($blocks[1] as $body) {
        foreach (array_filter(array_map('trim', explode(';', $body))) as $declaration) {
            expect($declaration)->toStartWith('--');
        }
    }
});

it('keeps the muted text of the sidebar readable on it, in every look', function (): void {
    // Every block that sets a sidebar colour sets its muted text too (B-121).
    preg_match_all('/([^{}]*)\{([^{}]*)\}/', uiCss(), $blocks, PREG_SET_ORDER);
    $checked = 0;

    foreach ($blocks as [, $selector, $body]) {
        if (preg_match('/--sidebar:\s*(#[0-9a-f]{6})/i', $body, $sidebar) !== 1) {
            continue;
        }

        expect(preg_match('/--sidebar-muted:\s*(#[0-9a-f]{6})/i', $body, $muted))->toBe(1, trim($selector))
            ->and(palContrast($muted[1], $sidebar[1]))->toBeGreaterThanOrEqual(4.5, trim($selector));
        $checked++;
    }

    expect($checked)->toBe(9);
});
