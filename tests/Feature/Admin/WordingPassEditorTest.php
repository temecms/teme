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

it('names the item’s own type in the side panel, its screen-reader line and the stale dialog', function (): void {
    expect(($this->flat)('pages/content/edit.tsx'))
        ->toContain('`${type?.singular ?? \'Item\'} settings`')
        ->toContain("railNoun={themeRegion !== null ? 'region' : noun}")
        ->toContain("noun={themeRegion !== null ? 'region' : noun}")
        ->not->toContain('Article settings')
        ->not->toContain('changed this article')
        ->and(($this->flat)('components/editor/editor-shell.tsx'))
        ->toContain('{`The ${railNoun}’s settings`}')
        ->not->toContain("The article's settings")
        ->and(($this->flat)('components/editor/stale-dialog.tsx'))
        ->toContain('{`Someone else changed this ${noun}`}');
});

it('says what the canonical address is for under its legend, before the choices', function (): void {
    $source = ($this->flat)('components/editor/rail/canonical-choice.tsx');
    $firstChoice = ($this->at)($source, 'id="editor-canonical-self"');

    expect(($this->at)($source, 'id="editor-canonical-hint"'))
        ->toBeGreaterThan(($this->at)($source, 'Canonical address </legend>'))
        ->toBeLessThan($firstChoice)
        ->and(($this->at)($source, 'Where the original of this {noun} lives'))->toBeLessThan($firstChoice);
});
