<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('strips Word\'s markup and keeps bold, italic, the list and the link', function (): void {
    $word = <<<'HTML'
<html xmlns:o="urn:schemas-microsoft-com:office:office"><head><meta name=Generator content="Microsoft Word 15"><style><!-- p.MsoNormal {mso-style-parent:""} --></style></head><body lang=EN-GB>
<!--StartFragment-->
<p class=MsoNormal style='mso-margin-top-alt:auto'><b><span lang=EN-GB style='font-family:"Calibri",sans-serif'>Gates</span></b><span lang=EN-GB> open at <i>six</i>, see <a href="https://example.org/tickets"><span style='color:#0563C1'>tickets</span></a>.<o:p></o:p></span></p>
<p class=MsoListParagraphCxSpFirst style='text-indent:-18.0pt;mso-list:l0 level1 lfo1'><![if !supportLists]><span style='font-family:Symbol'>·<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp; </span></span><![endif]><span lang=EN-GB>Bring a coat<o:p></o:p></span></p>
<p class=MsoListParagraphCxSpLast style='text-indent:-18.0pt;mso-list:l0 level1 lfo1'><![if !supportLists]><span style='font-family:Symbol'>·<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp; </span></span><![endif]><span lang=EN-GB>Bring a torch<o:p></o:p></span></p>
<p class=MsoNormal><font face="Calibri">Plain words</font></p>
<!--EndFragment-->
</body></html>
HTML;

    $html = histTight(histPaste(['html' => $word])['html']);

    expect($html)->not->toContain('mso-')
        ->not->toContain('class=')
        ->not->toContain('style=')
        ->not->toContain('lang=')
        ->not->toContain('<o:p')
        ->not->toContain('<font')
        ->not->toContain('<meta')
        ->not->toContain('<span')
        ->not->toContain('<!--')
        ->not->toContain('supportLists')
        ->toContain('<b>Gates</b>')
        ->toContain('<i>six</i>')
        ->toContain('<a href="https://example.org/tickets">tickets</a>')
        ->toContain('<ul><li>Bring a coat</li><li>Bring a torch</li></ul>')
        ->toContain('Plain words');
});

it('strips Google Docs\' wrapper and styles, and turns its bold and italic spans into marks', function (): void {
    $docs = '<meta charset="utf-8"><b style="font-weight:normal;" id="docs-internal-guid-1a2b3c4d-7fff-1234-5678-9abcdef01234">'
        .'<p dir="ltr" style="line-height:1.38;margin-top:0pt;"><span style="font-size:11pt;font-family:Arial,sans-serif;font-weight:700;font-style:normal;">Three days</span>'
        .'<span style="font-size:11pt;font-weight:400;font-style:normal;"> of music by the </span>'
        .'<span style="font-size:11pt;font-weight:400;font-style:italic;">quay</span><span style="font-size:11pt;">.</span></p>'
        .'<ul style="margin-top:0;padding-inline-start:48px;"><li dir="ltr" style="list-style-type:disc;" aria-level="1"><p dir="ltr" role="presentation" style="line-height:1.38;">'
        .'<span style="font-size:11pt;font-weight:400;">Tickets from </span><a href="https://example.org/tickets" style="text-decoration:none;"><span style="color:#1155cc;text-decoration:underline;">Friday</span></a>'
        .'</p></li></ul></b><br class="Apple-interchange-newline">';

    $html = histTight(histPaste(['html' => $docs])['html']);

    // The wrapper is font-weight:normal: unwrapped, never made bold.
    expect($html)->not->toContain('docs-internal-guid')
        ->not->toMatch('/<b[\s>]/')
        ->not->toContain('style=')
        ->not->toContain('class=')
        ->not->toContain('<meta')
        ->not->toContain('<span')
        ->toContain('<strong>Three days</strong> of music by the <em>quay</em>.')
        ->toContain('<ul><li>')
        ->toContain('<a href="https://example.org/tickets">Friday</a>');
});

it('leaves the editor\'s own clipboard as it is', function (): void {
    $own = '<p data-pm-slice="1 1 []">Gates open at <strong>six</strong>.</p><div data-type="callout" data-tone="note">Bring a coat.</div>';

    expect(histPaste(['html' => $own])['html'])->toBe($own);
});

it('turns a YouTube address on its own into an embed card\'s attributes', function (): void {
    $embed = histPaste(['text' => "  https://www.youtube.com/watch?v=dQw4w9WgXcQ \n"])['embed'];

    expect($embed)->toBe([
        'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'kind' => 'video',
        'provider' => 'youtube',
        'title' => null,
        'html' => null,
        'thumbnailMediaId' => null,
        'caption' => null,
    ]);
});

it('leaves an address no provider frames, and a sentence with an address in it, as ordinary text', function (string $text): void {
    expect(histPaste(['text' => $text])['embed'])->toBeNull();
})->with([
    'another site' => ['https://example.org/harbour-festival'],
    'a sentence' => ['Watch https://www.youtube.com/watch?v=dQw4w9WgXcQ tonight'],
    'not http' => ['javascript:alert(1)'],
]);

it('cleans every paste and makes an embed only from an empty paragraph', function (): void {
    expect(uiComponent('components/editor/use-teme-editor.ts'))
        ->toContain('transformPastedHTML')
        ->toContain('cleanPastedHtml(')
        ->toContain('handlePaste')
        ->toContain('embedFromPastedText(')
        ->toContain('ulid()')
        ->and(uiComponent('components/editor/prose-editor.tsx'))->toContain('embedProviders');
});

it('keeps a pasted table\'s spans and alignment, from align or from text-align', function (): void {
    $html = '<table style="border-collapse:collapse"><tr><th align="center" style="width:40%">Stall</th><th style="text-align: right; padding: 4px">Price</th></tr>'
        .'<tr><td colspan="2" rowspan="1" class="xl65" style="text-align:LEFT">Oysters</td><td style="text-align: justify">Bread</td></tr></table>';

    expect(histTight(histPaste(['html' => $html])['html']))
        ->toBe('<table><tr><th align="center">Stall</th><th align="right">Price</th></tr><tr><td colspan="2" rowspan="1" align="left">Oysters</td><td>Bread</td></tr></table>');
});

it('keeps a pasted cell\'s span as browsers read it, up to their limits', function (): void {
    $html = '<table><tr><td colspan="20000000" rowspan="70000">Wide</td><td colspan="0" rowspan="-1">None</td>'
        .'<th colspan=" 3px" rowspan="1000">Three</th><td colspan="x" rowspan="">Odd</td><td colspan="1000" rowspan="65534">Most</td></tr></table>';

    expect(histTight(histPaste(['html' => $html])['html']))
        ->toBe('<table><tr><td colspan="1000" rowspan="65534">Wide</td><td>None</td><th colspan="3" rowspan="1000">Three</th><td>Odd</td><td colspan="1000" rowspan="65534">Most</td></tr></table>');
});

it('keeps a pasted h1, h5 and h6 as headings the editor has', function (): void {
    expect(histTight(histPaste(['html' => '<h1 class="title">Harbour festival</h1><h2>Getting there</h2><h5>By ferry</h5><h6 id="x">Timetable</h6><p>Gates open at six.</p>'])['html']))
        ->toBe('<h2>Harbour festival</h2><h2>Getting there</h2><h4>By ferry</h4><h4>Timetable</h4><p>Gates open at six.</p>');
});

it('keeps an h1, h5 and h6 copied from another Tiptap editor as headings the editor has, touching nothing else', function (): void {
    expect(histPaste(['html' => '<h1 data-pm-slice="1 1 []">Harbour festival</h1><h5 data-id="9b2c6d1e">By ferry</h5><H6>Timetable</H6><hr><p data-id="x">Words</p>'])['html'])
        ->toBe('<h2 data-pm-slice="1 1 []">Harbour festival</h2><h4 data-id="9b2c6d1e">By ferry</h4><h4>Timetable</h4><hr><p data-id="x">Words</p>');
});

it('keeps the links the site can store, as it stores them, and unwraps the rest', function (): void {
    $html = '<p><a href="#getting-there">Getting there</a>, <a href="#the timetable">the timetable</a>, '
        .'<a href="//example.org/map">the map</a>, <a href="/tickets/">tickets</a>, <a href="/category/news/#top">news</a>, '
        .'<a href="/café/">the café</a>, <a href="javascript:alert(1)">prizes</a>, '
        .'<a href="ftp://example.org/files">files</a>, <a href="page.html">more</a>, <a href="#">top</a>, '
        .'<a href="https://example.org/a?b=1&amp;c=2">a site</a>, <a href="mailto:box@example.org">write</a></p>';

    expect(histTight(histPaste(['html' => $html])['html']))
        ->toBe('<p><a href="#getting-there">Getting there</a>, <a href="#the%20timetable">the timetable</a>, '
            .'<a href="https://example.org/map">the map</a>, <a href="/tickets/">tickets</a>, <a href="/category/news/#top">news</a>, '
            .'<a href="/caf%C3%A9/">the café</a>, prizes, files, more, top, '
            .'<a href="https://example.org/a?b=1&amp;c=2">a site</a>, <a href="mailto:box@example.org">write</a></p>');
});

it('turns a pasted link to this site\'s own full address into its path (rule 9)', function (): void {
    $html = '<p><a href="https://harbour.example/harbour-festival/">https</a>, <a href="http://Harbour.example/harbour-festival">http</a>, '
        .'<a href="//harbour.example/tickets/?day=1#times">no scheme</a>, <a href="https://harbour.example">home</a>, '
        .'<a href="https://elsewhere.example/harbour-festival/">elsewhere</a></p>';

    expect(histTight(histPaste(['html' => $html, 'site' => 'https://harbour.example'])['html']))
        ->toBe('<p><a href="/harbour-festival/">https</a>, <a href="/harbour-festival">http</a>, '
            .'<a href="/tickets/?day=1#times">no scheme</a>, <a href="/">home</a>, '
            .'<a href="https://elsewhere.example/harbour-festival/">elsewhere</a></p>');
});

it('keeps the best address to import a pasted image from (I-453)', function (string $html, string $expected): void {
    expect(histTight(histPaste(['html' => $html])['html']))->toBe($expected);
})->with([
    'the widest size WordPress lists' => [
        '<figure class="wp-block-image size-large"><img decoding="async" width="1024" height="683" src="https://photos.example/wp-content/uploads/2026/05/harbour-1024x683.jpg" alt="The harbour at dawn" class="wp-image-12" srcset="https://photos.example/wp-content/uploads/2026/05/harbour-1024x683.jpg 1024w, https://photos.example/wp-content/uploads/2026/05/harbour-300x200.jpg 300w, https://photos.example/wp-content/uploads/2026/05/harbour.jpg 2048w" sizes="(max-width: 1024px) 100vw, 1024px"/><figcaption class="wp-element-caption">Morning</figcaption></figure>',
        '<figure><img src="https://photos.example/wp-content/uploads/2026/05/harbour.jpg" alt="The harbour at dawn"><figcaption>Morning</figcaption></figure>',
    ],
    'a lazy loader\'s held-back address' => [
        '<p><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" data-lazy-src="https://photos.example/boats.jpg" alt="Boats"></p>',
        '<p><img src="https://photos.example/boats.jpg" alt="Boats"></p>',
    ],
    'another lazy loader, with src left empty' => [
        '<p><img src="" data-src="https://photos.example/nets.jpg" alt="Nets"></p>',
        '<p><img src="https://photos.example/nets.jpg" alt="Nets"></p>',
    ],
    'Jetpack\'s original over its resized copy' => [
        '<p><img src="https://i0.wp.com/photos.example/net.jpg?resize=640%2C427" data-orig-file="https://photos.example/net.jpg" alt="Nets"></p>',
        '<p><img src="https://photos.example/net.jpg" alt="Nets"></p>',
    ],
    'an address without its scheme' => [
        '<p><img src="//photos.example/gulls.jpg"></p>',
        '<p><img src="https://photos.example/gulls.jpg"></p>',
    ],
    'an image inside the paste, with nothing better' => [
        '<p><img src="data:image/png;base64,iVBORw0KGgo=" alt="Chart"></p>',
        '<p><img src="data:image/png;base64,iVBORw0KGgo=" alt="Chart"></p>',
    ],
    'the editor\'s own clipboard, untouched' => [
        '<p data-pm-slice="1 1 []"><img src="//photos.example/gulls.jpg" srcset="https://photos.example/gulls-big.jpg 900w"></p>',
        '<p data-pm-slice="1 1 []"><img src="//photos.example/gulls.jpg" srcset="https://photos.example/gulls-big.jpg 900w"></p>',
    ],
]);
