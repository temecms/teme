<?php

declare(strict_types=1);

it('draws each proposal under its own field, the excerpt\'s under the excerpt, and Suggest metadata at the head of Search and social (I-1313, I-230)', function (): void {
    $section = uiComponent('components/editor/rail/search-panel.tsx');
    $editor = uiComponent('pages/content/edit.tsx');

    // Whether $needle sits after $after and before $before in $source, all three present.
    $between = function (string $source, string $needle, string $after, string $before): bool {
        $at = strpos($source, $needle);

        return $at !== false && str_contains($source, $after) && str_contains($source, $before)
            && $at > (int) strpos($source, $after) && $at < (int) strpos($source, $before);
    };

    expect($between($section, '<SuggestMetadata', 'appears in search results and when it’s', 'id="editor-seo-title"'))->toBeTrue()
        ->and($between($section, 'handle="seo_title"', 'id="editor-seo-title-count"', 'htmlFor="editor-seo-description"'))->toBeTrue()
        ->and($between($section, 'handle="meta_description"', 'id="editor-seo-description-count"', 'id="editor-seo-noindex"'))->toBeTrue()
        ->and($between($section, 'handle="social_title"', 'id="editor-social-title-hint"', 'htmlFor="editor-social-description"'))->toBeTrue()
        ->and($between($section, 'handle="social_description"', 'id="editor-social-description-hint"', '<SocialImage'))->toBeTrue()
        ->and($between($editor, 'handle="excerpt"', 'id="editor-excerpt-hint"', '<FeaturedImage'))->toBeTrue()
        ->and($editor)->toContain('useMetadataBinding(')
        ->toContain('metadata={metadata}');
});

it('asks from the pane and the palette as the buttons do, and the card draws a text proposal only for an answer of one text (I-1318)', function (): void {
    $pane = uiComponent('components/editor/rail/assistant-panel.tsx');
    $palette = uiComponent('components/palette/palette-commands.ts');
    $shared = uiComponent('components/editor/rail/use-metadata.ts');

    expect($pane)->toContain('<SuggestMetadataPane ai={ai} />')
        ->not->toContain('Later (I-1313)')
        ->toContain('request.values.text !== undefined')
        ->toContain('<FieldsProposed')
        ->and($palette)->toContain("label: 'Suggest metadata for this post'")
        ->toContain("new CustomEvent('teme:assistant'")
        ->toContain("scope: 'Metadata: search and social text, and the excerpt'")
        ->not->toContain('Later (I-321): "Suggest metadata for this post"')
        ->and($shared)->toContain("scope: 'Metadata: search and social text, and the excerpt'");
});
