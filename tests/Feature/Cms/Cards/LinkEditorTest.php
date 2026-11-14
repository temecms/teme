<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentNormaliser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps, rewrites or unlinks an address in the editor exactly as the server does', function (): void {
    $addresses = [
        'https://example.org/tickets?day=1' => 'https://example.org/tickets?day=1',
        '//example.org/map' => 'https://example.org/map',
        '#getting there café' => '#getting%20there%20caf%C3%A9',
        '  mailto:box@example.org ' => 'mailto:box@example.org',
        'teme:content/01K5Z9P2QH7N3X4C8V6B0M1T2R' => 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r',
        'teme:content/01K5Z9P2QH7N3X4C8V6B0M1T2R#Getting There' => 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#Getting%20There',
        '/no-such-page/' => '/no-such-page/',
        '/café menu/?q=a b#top' => '/caf%C3%A9%20menu/?q=a%20b#top',
        '/' => '/',
        '#' => null,
        'teme:content/../x' => null,
        'javascript:alert(1)' => null,
        'ftp://example.org/files' => null,
        'page.html' => null,
        'https:///harbour-festival/' => null,
        '/\\evil.example/' => null,
        '///evil.example/' => null,
    ];

    $editor = guardCall('components/editor/link-address.ts', 'normaliseHref', array_map(fn (string $href): array => [$href], array_keys($addresses)));
    $server = array_map(
        fn (string $href): ?string => DocumentNormaliser::normalise(docWith(guardLink($href, 'words')))['content'][0]['content'][0]['marks'][0]['attrs']['href'] ?? null,
        array_keys($addresses),
    );

    expect($editor)->toBe(array_values($addresses))
        ->and($server)->toBe(array_values($addresses));
});

it('leaves a path an article may have for the server to resolve', function (): void {
    expect(guardCall('components/editor/link-address.ts', 'normaliseHref', [
        ['/harbour-festival/'],
        [' /tickets/?day=1#times '],
    ]))->toBe(['/harbour-festival/', '/tickets/?day=1#times']);
});

it('makes an autosave\'s link changes in the editor, keeping the words of a link it removed', function (): void {
    $answer = guardChange(docWith(['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'the festival', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/harbour-festival/#tickets']]]],
        ['type' => 'text', 'text' => ' and '],
        ['type' => 'text', 'text' => 'files', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'ftp://example.org/files']]]],
    ]]), [
        ['from' => '/harbour-festival/#tickets', 'to' => 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#tickets'],
        ['from' => 'ftp://example.org/files', 'to' => null],
    ]);

    expect($answer['changed'])->toBeTrue()
        ->and($answer['document']['content'][0]['content'])->toBe([
            ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#tickets', 'target' => null, 'rel' => null, 'class' => null, 'title' => null]]], 'text' => 'the festival'],
            ['type' => 'text', 'text' => ' and files'],
        ]);
});

it('changes nothing when no link in the editor has the address the server changed', function (): void {
    $answer = guardChange(docWith(guardLink('https://example.org/', 'elsewhere')), [
        ['from' => 'ftp://example.org/files', 'to' => null],
    ]);

    expect($answer['changed'])->toBeFalse();
});

it('names the links a save removed, and says nothing of those it kept or rewrote', function (): void {
    $why = 'A link can go to an item on this site, a page on this site, a web or email address, a phone number, or a place on a page.';

    expect(guardCall('components/editor/link-changes.ts', 'linkChangesNotice', [
        [[['from' => '/harbour-festival/', 'to' => 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r'], ['from' => 'ftp://example.org/files', 'to' => null]]],
        [[['from' => 'ftp://a.example/', 'to' => null], ['from' => 'sms:+441234567890', 'to' => null], ['from' => 'ftp://a.example/', 'to' => null]]],
        [[['from' => 'javascript:alert(1)', 'to' => null], ['from' => 'ftp://a.example/', 'to' => null], ['from' => 'sms:+441234567890', 'to' => null]]],
        [[['from' => 'ftp://example.org/'.str_repeat('x', 40), 'to' => null]]],
        [[['from' => '/café/', 'to' => '/caf%C3%A9/'], ['from' => '//example.org/', 'to' => 'https://example.org/']]],
    ]))->toBe([
        "A link was removed and its words kept: “ftp://example.org/files”. {$why}",
        "2 links were removed and their words kept: “ftp://a.example/” and “sms:+441234567890”. {$why}",
        "3 links were removed and their words kept: “javascript:alert(1)”, “ftp://a.example/” and “sms:+441234567890”. {$why}",
        'A link was removed and its words kept: “ftp://example.org/'.str_repeat('x', 21)."…”. {$why}",
        null,
    ]);
});

it('counts a link to a place in another article as a link to that article, in the rail', function (): void {
    expect(guardNode('core-checks.mjs', [
        'document' => docWith(guardLink('teme:content/01k5z9p2qh7n3x4c8v6b0m1t2b#times', 'the market\'s times')),
        'ownId' => '01k5z9p2qh7n3x4c8v6b0m1t2a',
    ]))->toBe(['images' => 0, 'links' => 1]);
});

it('hands each autosave\'s link changes to the editor and the notice', function (): void {
    expect(uiComponent('components/editor/use-autosave.ts'))->toContain('linksChanged.current?.(links)')
        ->and(uiComponent('pages/content/edit.tsx'))
        ->toContain('applyLinkChanges(editor.state, changes)')
        ->toContain('linkChangesNotice(changes)');
});

it('turns this site\'s full address into its path in the editor, for the server to resolve (rule 9)', function (): void {
    $site = 'https://harbour.example';

    $addresses = [
        'https://harbour.example/harbour-festival/' => '/harbour-festival/',
        'http://Harbour.Example/harbour-festival?day=1#times' => '/harbour-festival?day=1#times',
        '//harbour.example/harbour-festival/' => '/harbour-festival/',
        'https://www.harbour.example/harbour-festival/' => '/harbour-festival/',
        'https://harbour.example:443/harbour-festival/' => '/harbour-festival/',
        'https://harbour.example' => '/',
        'https://elsewhere.example/harbour-festival/' => 'https://elsewhere.example/harbour-festival/',
        'https://harbour.example:8443/harbour-festival/' => 'https://harbour.example:8443/harbour-festival/',
        'https://ada@harbour.example/harbour-festival/' => 'https://ada@harbour.example/harbour-festival/',
    ];

    expect(guardCall('components/editor/link-address.ts', 'normaliseHref', array_map(fn (string $href): array => [$href, $site], array_keys($addresses))))
        ->toBe(array_values($addresses))
        // With no site given, as Tiptap's isAllowedUri asks, a full address is only checked.
        ->and(guardCall('components/editor/link-address.ts', 'normaliseHref', [['https://harbour.example/harbour-festival/']]))
        ->toBe(['https://harbour.example/harbour-festival/']);
});

it('makes an address typed in the link box one the server keeps, or says why not', function (): void {
    $site = 'https://harbour.example';

    expect(guardCall('components/editor/link-address.ts', 'typedAddress', [
        ['example.org', $site], ['#top', $site], ['/about/', $site], ['//example.org/x', $site],
        ['https://harbour.example/about/', $site], ['harbour.example/about', $site], ['mailto:box@example.org', $site],
        ['#', $site], ['javascript:alert(1)', $site], ['  ', $site],
    ]))->toBe([
        ['href' => 'https://example.org'],
        ['href' => '#top'],
        ['href' => '/about/'],
        ['href' => 'https://example.org/x'],
        ['href' => '/about/'],
        ['href' => '/about'],
        ['href' => 'mailto:box@example.org'],
        ['error' => 'Write the name of the place after the #.'],
        ['error' => 'A link can go to an http or https address, an email (mailto:), a phone number (tel:), a page on this site (/…) or a place on this page (#…).'],
        ['error' => 'Type an address first.'],
    ]);
});

it('gives the paste cleaner and the link box the site\'s address, and never adds https:// to a place or a path', function (): void {
    expect(uiComponent('components/editor/link-popover.tsx'))->toContain('typedAddress(address, siteUrl)')->not->toContain('withProtocol')
        ->and(uiComponent('components/editor/use-teme-editor.ts'))->toContain('cleanPastedHtml(html, site.current)')
        ->and(uiComponent('pages/content/edit.tsx'))->toMatch('/<ProseEditor\b(?:(?!\/>).)*siteUrl=\{page\.siteUrl\}/s');
});
