<?php

declare(strict_types=1);

use App\Domain\Site\Document\JsonLd;
use App\Domain\Site\Document\JsonLdNode;

it('encodes the graph so no text can end its script or open a comment, keeps line separators escaped and & as it is, and decodes to the same values (I-698)', function (): void {
    $hostile = "</script><script>alert(1)</script> <!-- \u{2028} \u{2029} Fish & Chips";
    $graph = JsonLd::graph([new JsonLdNode([
        '@id' => 'https://example.test/harbour-news/#article',
        '@type' => 'Article',
        'headline' => $hostile,
        'author' => [['@type' => 'Person', 'name' => $hostile]],
        'keywords' => [$hostile, 'Tides'],
    ])]);

    $json = JsonLd::encode($graph);

    expect(stripos($json, '</script'))->toBeFalse()
        ->and($json)->not->toContain('<')
        ->and($json)->not->toContain('<!--')
        ->and($json)->toContain('\u2028')
        ->and($json)->toContain('\u2029')
        ->and($json)->toContain('Fish & Chips')
        ->and($json)->not->toContain('&amp;')
        ->and($json)->toContain('"@id":"https://example.test/harbour-news/#article"')
        ->and(json_decode($json, true, flags: JSON_THROW_ON_ERROR))->toBe(['@context' => 'https://schema.org', '@graph' => $graph]);
});

it('merges nodes that share an @id: the first non-empty value stands, an empty one is filled, types and lists are joined, objects merge deeply (I-697)', function (): void {
    $graph = JsonLd::graph([
        new JsonLdNode([
            '@id' => 'https://example.test/#organization',
            '@type' => 'NewsMediaOrganization',
            'name' => 'The Ledger',
            'description' => '',
            'sameAs' => ['https://social.example/@ledger'],
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Garden City'],
        ]),
        new JsonLdNode(['@id' => 'https://example.test/#website', '@type' => 'WebSite', 'name' => 'Ledger']),
        new JsonLdNode([
            '@id' => 'https://example.test/#organization',
            '@type' => ['Organization', 'NewsMediaOrganization'],
            'name' => 'Another name',
            'description' => 'Local news',
            'sameAs' => ['https://social.example/@ledger', 'https://video.example/ledger'],
            'address' => ['addressLocality' => 'Elsewhere', 'postalCode' => '12345'],
            'logo' => ['@id' => 'https://example.test/#logo'],
        ]),
    ]);

    expect($graph)->toBe([
        [
            '@id' => 'https://example.test/#organization',
            '@type' => ['NewsMediaOrganization', 'Organization'],
            'name' => 'The Ledger',
            'description' => 'Local news',
            'sameAs' => ['https://social.example/@ledger', 'https://video.example/ledger'],
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Garden City', 'postalCode' => '12345'],
            'logo' => ['@id' => 'https://example.test/#logo'],
        ],
        ['@id' => 'https://example.test/#website', '@type' => 'WebSite', 'name' => 'Ledger'],
    ]);
});

it('refuses a node it couldn\'t place in the graph or encode: no @id or one that isn\'t an address, no @type, its own @context, an object, a number JSON can\'t hold, text that isn\'t UTF-8 (I-712)', function (string $case): void {
    $node = ['@id' => 'https://example.test/#website', '@type' => 'WebSite'];

    expect(fn () => new JsonLdNode(match ($case) {
        'no @id' => ['@type' => 'WebSite'],
        'an @id that isn\'t an address' => ['@id' => '#website', '@type' => 'WebSite'],
        'no @type' => ['@id' => 'https://example.test/#website'],
        'its own @context' => [...$node, '@context' => 'https://schema.org'],
        'an object' => [...$node, 'publisher' => new stdClass],
        'a number JSON can\'t hold' => [...$node, 'ratingValue' => INF],
        default => [...$node, 'name' => "\xB1\x31"],
    }))->toThrow(InvalidArgumentException::class);
})->with(['no @id', 'an @id that isn\'t an address', 'no @type', 'its own @context', 'an object', 'a number JSON can\'t hold', 'text that isn\'t UTF-8']);
