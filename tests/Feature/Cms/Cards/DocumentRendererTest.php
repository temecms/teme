<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\PlainText;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    // The golden files hold the good fixture's image sizes: the shipping
    // theme's own are its design to change (1.6g).
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
    edFixtureMedia($this->author);
});

it('renders prose as clean HTML', function (): void {
    $html = app(DocumentRenderer::class)->html(docWith(
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'What\'s on']]],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Three days of '],
            ['type' => 'text', 'text' => 'music', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' by the quay.'],
        ]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [docText('Friday')]]]],
    ));

    expect($html)->toBe('<h2>What&#039;s on</h2><p>Three days of <strong>music</strong> by the quay.</p><ul><li><p>Friday</p></li></ul>');
});

it('renders an image card from the media item, never from a stored URL', function (): void {
    $image = mediaUpload($this->author, mediaJpeg(1200, 800), 'harbour.jpg', allowDuplicate: true)->refresh();

    $html = app(DocumentRenderer::class)->html(docWith(['type' => 'image', 'attrs' => [
        'id' => str_repeat('A', 26), 'mediaId' => $image->id, 'alt' => 'Boats', 'caption' => 'The quay', 'credit' => 'Harbour Trust', 'width' => 'wide',
    ]]));

    expect($html)->toContain('<figure class="card-image card-image--wide">')
        ->and($html)->toContain('alt="Boats"')
        ->and($html)->toContain('/storage/')
        ->and($html)->toContain('<figcaption>The quay<span class="credit">Harbour Trust</span></figcaption>');
});

it('renders nothing for a card whose media is gone, and leaves the rest', function (): void {
    $html = app(DocumentRenderer::class)->html(docWith(
        ['type' => 'image', 'attrs' => ['id' => str_repeat('A', 26), 'mediaId' => str_repeat('Z', 26)]],
        docText('Still here'),
    ));

    expect($html)->toBe('<p>Still here</p>');
});

it('resolves an internal link at render time, and unlinks a draft or one that is gone', function (): void {
    $content = lifecycleDraft($this->author, 'Night market');
    $draft = lifecycleDraft($this->author, 'Not yet');
    listsPublish($content, $this->author);
    $link = fn (string $id): array => docWith(['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'See it', 'marks' => [['type' => 'link', 'attrs' => ['href' => "teme:content/{$id}"]]]],
    ]]);

    // A post's pattern is /{slug}/ (CoreTypes).
    expect(app(DocumentRenderer::class)->html($link($content->id)))->toBe('<p><a href="/night-market/">See it</a></p>')
        ->and(app(DocumentRenderer::class)->html($link($draft->id)))->toBe('<p>See it</p>')
        ->and(app(DocumentRenderer::class)->html($link(str_repeat('z', 26))))->toBe('<p>See it</p>');
});

it('renders an embed inside a sandboxed frame, never as stored markup', function (): void {
    $html = app(DocumentRenderer::class)->html(docWith(['type' => 'embed', 'attrs' => [
        'id' => str_repeat('A', 26), 'url' => 'https://www.youtube.com/watch?v=abc', 'kind' => 'video',
        'provider' => 'YouTube', 'title' => 'Highlights', 'html' => '<script>alert(1)</script>',
    ]]));

    expect($html)->toContain('<iframe')->and($html)->toContain('sandbox=')
        ->and($html)->not->toContain('<script');
});

it('gathers the plain text and counts the words, cards included', function (): void {
    $document = docWith(
        docText('Three days of music by the quay.'),
        ['type' => 'callout', 'attrs' => ['id' => str_repeat('A', 26), 'tone' => 'note', 'text' => 'Tickets in July.']],
        ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'echo 1;']]],
    );

    expect(PlainText::of($document))->toBe('Three days of music by the quay. Tickets in July.')
        ->and(PlainText::words($document))->toBe(10);
});

it('renders every fixture to its expected HTML', function (string $name): void {
    $document = json_decode(file_get_contents(base_path("tests/Fixtures/Documents/{$name}.json")), true);
    $expected = trim(file_get_contents(base_path("tests/Fixtures/Documents/{$name}.html")));

    expect(app(DocumentRenderer::class)->html($document))->toBe($expected);
})->with(['prose', 'callout', 'snippet', 'button', 'image', 'gallery', 'embed', 'aligned', 'code-language', 'ordered-start', 'table-align', 'links', 'outside-image']);

it('draws a table cell stored with no attributes, as the database gives back "attrs": {}', function (): void {
    $cell = fn (string $type, string $text): array => ['type' => $type, 'attrs' => [], 'content' => [docText($text)]];
    $document = docWith(['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
        $cell('tableHeader', 'Stall'),
        $cell('tableCell', 'Oysters'),
    ]]]]);

    docValidate($document);

    expect(app(DocumentRenderer::class)->html($document))
        ->toBe('<table><tbody><tr><th><p>Stall</p></th><td><p>Oysters</p></td></tr></tbody></table>');
});
