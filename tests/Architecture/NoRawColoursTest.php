<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Tailwind's own palette, raw hex and raw colour functions are all ways of
 * bypassing the tokens. The design system is only a system if nothing does.
 */
it('writes no raw colour in any component or page', function (): void {
    $palette = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
    $offences = [];

    // exclude() skips directories by name, never files: Wayfinder's three
    // generated, git-ignored folders. notPath('actions') matches any path
    // containing the word, so it would also skip components/list/row-actions.tsx.
    $files = Finder::create()->files()->in(resource_path('js'))->name(['*.tsx', '*.ts'])
        ->exclude(['actions', 'routes', 'wayfinder']);

    foreach ($files as $file) {
        foreach (explode("\n", $file->getContents()) as $number => $line) {
            $found = preg_match("/\\b(?:bg|text|border|ring|fill|stroke|from|via|to|decoration|outline|shadow|divide|accent|caret|placeholder)-(?:{$palette})-\\d{2,3}\\b/", $line) === 1
                // `&#039;` is an HTML character reference, not a colour.
                || preg_match('/(?<!&)#[0-9a-fA-F]{3,8}\b/', $line) === 1
                || preg_match('/\b(?:oklch|rgb|rgba|hsl|hsla)\(/', $line) === 1
                || preg_match('/\btext-(?:white|black)\b/', $line) === 1;

            if ($found) {
                $offences[] = $file->getRelativePathname().':'.($number + 1).'  '.trim($line);
            }
        }
    }

    expect($offences)->toBe([], "Raw colours:\n".implode("\n", $offences));
});
