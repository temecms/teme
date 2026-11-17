<?php

declare(strict_types=1);

it('draws the plugins\' checks in the section, after core\'s own (I-169, I-286)', function (): void {
    $section = uiComponent('components/editor/rail/search-panel.tsx');

    expect($section)->not->toContain('Later (I-286)')
        ->not->toContain('Later (1.9)')
        ->toContain('data-test="search-check-line"')
        ->toContain('<PluginChecks')
        ->and(strpos($section, '<PluginChecks'))->toBeGreaterThan((int) strpos($section, 'data-test="search-check-line"'));
});

it('opens the editor without a canonical that names an item gone from the site, and makes one list of checks for every place (I-292, I-880)', function (): void {
    expect(uiComponent('pages/content/edit.tsx'))
        ->toContain('withoutGoneCanonical(session.fields, page.seo.canonicalItem)')
        ->toContain('useSearchPreview(')
        ->toContain('checks={search.checks}')
        ->and(uiComponent('components/editor/rail/search-panel.tsx'))->toContain('check.search')
        ->and(uiComponent('components/editor/rail/canonical-choice.tsx'))->toContain('canonicalAddressProblem(');
});

it('fills the search title with the browser\'s twin of the server\'s filler, and measures it as results do (I-290, I-882)', function (): void {
    expect(uiComponent('components/editor/use-search-preview.ts'))
        ->toContain("import { fillTitle } from '@/lib/title-template';")
        ->toContain('textWidth(title.text, 20)')
        ->and(uiComponent('lib/text-width.ts'))->toContain('Arial, Helvetica, sans-serif');
});
