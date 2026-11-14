<?php

declare(strict_types=1);

beforeEach(function (): void {
    // A file's source with each run of whitespace made one space, so a
    // sentence Prettier wraps reads as one line.
    $this->flat = fn (string $path): string => (string) preg_replace('/\s+/', ' ', uiComponent($path));

    // Where a piece of source starts; the test fails when it isn't there.
    $this->at = function (string $source, string $needle, int $from = 0): int {
        $at = strpos($source, $needle, $from);
        expect($at)->not->toBeFalse("Not in the source: {$needle}");

        return (int) $at;
    };
});

it('draws Open Settings › General as a button beside the site-settings line on Themes', function (): void {
    $source = ($this->flat)('pages/themes/index.tsx');
    $line = ($this->at)($source, 'brand colour stay when you change theme.');

    expect(($this->at)($source, 'asChild', $line))->toBeLessThan(($this->at)($source, 'Open Settings › General', $line))
        ->and(($this->at)($source, 'Open Settings › General', $line))->toBeLessThan(($this->at)($source, '{page.preview !== null && (', $line))
        ->and($source)->not->toContain('are site settings')
        ->not->toContain('Change them in');
});

it('puts Edit menus in the Menus panel’s header on Themes', function (): void {
    $source = ($this->flat)('pages/themes/index.tsx');
    $header = ($this->at)($source, '<PanelHeader id="design-menus">');
    $end = ($this->at)($source, '</PanelHeader>', $header);

    expect(substr($source, $header, $end - $header))->toContain('Edit menus')->toContain('asChild')
        ->and($source)->not->toContain('Edit menus on Content');
});

it('offers the way on as the header’s button when comments are switched off', function (): void {
    $source = ($this->flat)('pages/comments/off.tsx');

    expect(($this->at)($source, 'action={'))->toBeLessThan(($this->at)($source, "'Switch comments on'"))
        ->and($source)->toContain("'Go to Home'")
        ->toContain('asChild')
        ->not->toContain('<p>')
        ->not->toContain('in Settings › Discussion');
});

it('offers the comments a person looks after as the header’s button', function (): void {
    $source = ($this->flat)('pages/comments/refused.tsx');

    expect(($this->at)($source, 'action={'))->toBeLessThan(($this->at)($source, '{back.label}'))
        ->and($source)->toContain('asChild')
        ->not->toContain('<p>');
});
