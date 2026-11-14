<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Cards\CardManifest;
use App\Domain\Cards\CardMedia;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\DocumentSchema;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('keeps, repairs or unlinks a link by ID in the editor exactly as the server does, and leaves addresses for the server to resolve', function (): void {
    $addresses = [
        'teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s' => 'teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s',
        'TEME:Term/01K5Z9P2QH7N3X4C8V6B0M1T2S#Latest News' => 'teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s#Latest%20News',
        'teme:user/42' => 'teme:user/42',
        'teme:user/42#' => 'teme:user/42',
        'teme:media/01k5z9p2qh7n3x4c8v6b0m1t2t#page=3' => 'teme:media/01K5Z9P2QH7N3X4C8V6B0M1T2T#page=3',
        'teme:content/01K5Z9P2QH7N3X4C8V6B0M1T2R#times' => 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#times',
        'teme:user/0' => null,
        'teme:user/042' => null,
        'teme:user/1234567890123456789' => null,
        'teme:widget/01k5z9p2qh7n3x4c8v6b0m1t2s' => null,
        'teme:term/' => null,
        'teme:media/01K5Z9P2QH7N3X4C8V6B0M1T2T?download=1' => null,
    ];

    $editor = guardCall('components/editor/link-address.ts', 'normaliseHref', array_map(fn (string $href): array => [$href], array_keys($addresses)));
    $server = array_map(
        fn (string $href): ?string => DocumentNormaliser::normalise(docWith(guardLink($href, 'words')))['content'][0]['content'][0]['marks'][0]['attrs']['href'] ?? null,
        array_keys($addresses),
    );

    expect($editor)->toBe(array_values($addresses))
        ->and($server)->toBe(array_values($addresses))
        // Only the server can tell what a path or a file's address names.
        ->and(guardCall('components/editor/link-address.ts', 'normaliseHref', [
            ['/category/harbour/'],
            ['https://harbour.example/storage/media/01K5Z9P2QH7N3X4C8V6B0M1T2T/v1/annual-report.pdf', 'https://harbour.example'],
        ]))->toBe(['/category/harbour/', '/storage/media/01K5Z9P2QH7N3X4C8V6B0M1T2T/v1/annual-report.pdf']);
});

it('reads a link by ID in the editor as the server reads it', function (): void {
    $hrefs = [
        'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#times', 'teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s', 'teme:user/42',
        'teme:media/01K5Z9P2QH7N3X4C8V6B0M1T2T#page=3', 'teme:media/01k5z9p2qh7n3x4c8v6b0m1t2t', '/category/news/', 'https://example.org/',
    ];

    expect(guardCall('components/editor/link-address.ts', 'linkTarget', array_map(fn (string $href): array => [$href], $hrefs)))
        ->toBe(array_map(fn (string $href): ?array => DocumentSchema::linkTarget($href), $hrefs));
});

it('treats a link by ID of every kind as one the link box made, so it shows no address for it', function (): void {
    expect(guardCall('components/editor/schema.ts', 'isInternalLink', [
        ['teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s'], ['teme:user/42'], ['teme:media/01K5Z9P2QH7N3X4C8V6B0M1T2T'],
        ['teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#times'], ['/category/news/'], ['https://example.org/'],
    ]))->toBe([true, true, true, true, false, false]);
});

it('draws links by ID in the editor’s renderer as the site draws them', function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $editor = User::factory()->create()->assignRole('editor');
    $harbour = typesTerm($editor, 'category', 'Harbour');
    $ada = lkAuthor('Ada Harbour');
    $report = mediaUpload($editor, mediaPdf(), 'Annual report.pdf');
    $image = lkImage($editor);

    $document = docWith(
        lkLink("teme:term/{$harbour->id}#latest", 'the harbour'),
        lkLink("teme:user/{$ada->id}", 'Ada', ['target' => '_blank']),
        lkLink("teme:media/{$report->id}#page=2", 'the report'),
        lkLink("teme:media/{$image->id}", 'the picture, larger'),
        lkLink('teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s', 'a category since deleted'),
    );
    $links = ["term:{$harbour->id}" => Addresses::relativeUrl($harbour), "user:{$ada->id}" => Addresses::relativeUrl($ada)];

    $browser = guardCall('components/editor/cards/render.ts', 'renderDocument', [[
        $document, (object) app(CardMedia::class)->forDocument($document), CardManifest::forBrowser(app(CardRegistry::class)), $links,
    ]]);

    expect($browser[0])->toContain('href="'.Addresses::relativeUrl($harbour).'#latest"')
        ->and(edNormalise($browser[0]))->toBe(edNormalise(app(DocumentRenderer::class)->html($document)));
});

it('gives the editor’s guard a document for each kind of link by ID, all of which the server keeps (I-452)', function (): void {
    $documents = array_filter(guardEditor()['documents'], fn (string $name): bool => str_contains($name, 'by ID'), ARRAY_FILTER_USE_KEY);

    expect($documents)->toHaveCount(5);

    foreach ($documents as $document) {
        $href = DocumentNormaliser::normalise($document)['content'][0]['content'][0]['marks'][0]['attrs']['href'] ?? '';

        expect(DocumentSchema::linkTarget((string) $href))->not->toBeNull();
    }
});
