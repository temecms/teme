<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentRenderer;
use App\Domain\Import\Convert\ConversionItem;
use App\Domain\Import\Phases\Finish\Fidelity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Convert\WordPressContent;

uses(RefreshDatabase::class);

/*
 * Anonymized samples from the fresh-site reports (import v1 fixes): each
 * converts as the import already must — FAQ as headings and paragraphs,
 * tables of contents dropped with a report line, Canvas flattened,
 * WPBakery and tagDiv unwrapped, a registered app-store shortcode kept as
 * Imported content, [State] kept as text — and fidelity counts no loss for
 * the dropped tables of contents.
 */

it('converts anonymized real-markup samples as the import must', function (): void {
    $dir = base_path('tests/Fixtures/Import/real-markup');
    $harbour = '01K5Z9P2QH7N3X4C8V6B0M1M41';
    $load = fn (string $name): array => json_decode((string) file_get_contents("{$dir}/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);

    $yoastFaq = $load('yoast-faq');
    $yoast = WordPressContent::convert($yoastFaq['blocks']);
    wpAccepted($yoast->document);
    expect(array_column($yoast->document['content'], 'type'))->toBe(['heading', 'paragraph'])
        ->and($yoast->document['content'][0]['content'][0]['text'])->toBe('Is it free?')
        ->and($yoast->document['content'][1]['content'][0]['text'])->toBe('Yes, always.')
        ->and(Fidelity::compare($yoastFaq['wordpress_html'], app(DocumentRenderer::class)->html($yoast->document))->lost)->toBe(0);

    $rankFaq = $load('rank-math-faq');
    $rank = WordPressContent::convert($rankFaq['blocks']);
    wpAccepted($rank->document);
    expect(array_column($rank->document['content'], 'type'))->toBe(['heading', 'paragraph'])
        ->and($rank->document['content'][0]['content'][0]['text'])->toBe('How long?')
        ->and($rank->document['content'][1]['content'][0]['text'])->toBe('Two days.')
        ->and(Fidelity::compare($rankFaq['wordpress_html'], app(DocumentRenderer::class)->html($rank->document))->lost)->toBe(0);

    $simpletoc = $load('simpletoc');
    $simple = WordPressContent::convert($simpletoc['blocks']);
    wpAccepted($simple->document);
    expect(wpWithoutIds($simple->document)['content'])->toBe([['type' => 'paragraph']])
        ->and(array_map(fn ($m) => [$m->code, $m->context['name'] ?? null], $simple->messages))->toBe([
            ['block.contents-list', 'simpletoc/toc'],
        ])
        ->and(Fidelity::compare($simpletoc['wordpress_html'], app(DocumentRenderer::class)->html($simple->document))->lost)->toBe(0);

    $yoastToc = $load('yoast-toc');
    $yToc = WordPressContent::convert($yoastToc['blocks']);
    wpAccepted($yToc->document);
    expect(wpWithoutIds($yToc->document)['content'])->toBe([['type' => 'paragraph']])
        ->and(array_map(fn ($m) => [$m->code, $m->context['name'] ?? null], $yToc->messages))->toBe([
            ['block.contents-list', 'yoast-seo/table-of-contents'],
        ])
        ->and(Fidelity::compare($yoastToc['wordpress_html'], app(DocumentRenderer::class)->html($yToc->document))->lost)->toBe(0);

    $canvas = $load('canvas');
    $flat = WordPressContent::convert($canvas['blocks']);
    wpAccepted($flat->document);
    expect(wpWithoutIds($flat->document)['content'])->toBe([
        WordPressContent::p('First canvas'),
        WordPressContent::p('Second canvas'),
    ])->and($flat->messages)->toBe([]);

    $wpbakery = $load('wpbakery-row');
    $vc = WordPressContent::convert(
        $wpbakery['blocks'],
        item: new ConversionItem($wpbakery['id']),
        attachments: [41 => $harbour],
    );
    wpAccepted($vc->document);
    expect(array_column($vc->document['content'], 'type'))->toContain('image')
        ->and(array_map(fn ($m) => [$m->code, $m->context['name'] ?? null], $vc->messages))->toContain(
            ['shortcode.unwrapped', 'vc_row'],
            ['shortcode.unwrapped', 'vc_column'],
            ['shortcode.unwrapped', 'vc_column_text'],
        );

    $tagdiv = $load('tagdiv-row');
    $td = WordPressContent::convert($tagdiv['blocks']);
    wpAccepted($td->document);
    expect(wpWithoutIds($td->document)['content'])->toBe([
        WordPressContent::p('Inside the row'),
    ])->and(array_map(fn ($m) => [$m->code, $m->context['name'] ?? null], $td->messages))->toContain(
        ['shortcode.unwrapped', 'tdm_block_column_title'],
        ['shortcode.unwrapped', 'tdc_zone'],
    );

    $appStore = $load('app-store-shortcode');
    $imported = WordPressContent::convert(
        $appStore['blocks'],
        item: new ConversionItem($appStore['id'], $appStore['shortcodes']),
        shortcodes: $appStore['registered'],
    );
    wpAccepted($imported->document);
    expect($imported->document['content'][0]['type'])->toBe('imported')
        ->and($imported->document['content'][0]['attrs']['sourceName'])->toBe('app_store')
        ->and(array_column(WordPressContent::said($imported), 0))->toBe(['shortcode.imported']);

    $state = $load('state-paragraph');
    $kept = WordPressContent::convert($state['blocks']);
    wpAccepted($kept->document);
    expect(wpWithoutIds($kept->document)['content'])->toBe([
        WordPressContent::p('See [State] and [mg] here.'),
    ])->and($kept->messages)->toBe([]);
});
