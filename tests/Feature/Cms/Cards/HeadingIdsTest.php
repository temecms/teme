<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\HeadingIds;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\RenderContext;
use App\Domain\Site\ArticleViews;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
    $this->render = fn (array ...$nodes): string => app(DocumentRenderer::class)->html(docWith(...$nodes));
    $this->heading = fn (int $level, string $text, ?string $id = null): array => [
        'type' => 'heading',
        'attrs' => array_filter(['level' => $level, 'id' => $id], fn (mixed $value): bool => $value !== null),
        'content' => [['type' => 'text', 'text' => $text]],
    ];
});

it('writes a heading’s permanent node id as its HTML id', function (): void {
    $id = '01K5Z9P2QH7N3X4C8V6B0M1T3D';

    expect(($this->render)(($this->heading)(2, 'Tickets', $id)))
        ->toBe('<h2 id="'.$id.'">Tickets</h2>');
});

it('makes a heading’s id from its words when it has no node id', function (): void {
    expect(($this->render)(($this->heading)(2, "What's on")))
        ->toBe('<h2 id="whats-on">What&#039;s on</h2>');
});

it('uses heading as the id when the words make no slug', function (): void {
    expect(($this->render)(($this->heading)(2, '!!!'), ($this->heading)(3, '…')))
        ->toBe('<h2 id="heading">!!!</h2><h3 id="heading-2">…</h3>');
});

it('keeps heading ids unique on the page', function (): void {
    $html = ($this->render)(
        ($this->heading)(2, 'Times'),
        ($this->heading)(3, 'Times'),
        ($this->heading)(2, 'Times'),
    );

    expect($html)->toBe(
        '<h2 id="times">Times</h2><h3 id="times-2">Times</h3><h2 id="times-3">Times</h2>',
    );
});

it('does not reuse landmark ids core and the default theme already write', function (): void {
    expect(($this->render)(($this->heading)(2, 'Main')))
        ->toBe('<h2 id="main-2">Main</h2>');
});

it('shares one HeadingIds between the body and a region so the same words get different ids', function (): void {
    $ids = app(HeadingIds::class);
    $heading = ($this->heading)(2, 'Times');
    $context = fn (): RenderContext => new RenderContext(app(LinkResolver::class), headings: $ids);

    $body = app(DocumentRenderer::class)->html(docWith($heading), $context());
    $region = app(DocumentRenderer::class)->html(docWith($heading), $context());

    expect($body)->toBe('<h2 id="times">Times</h2>')
        ->and($region)->toBe('<h2 id="times-2">Times</h2>');
});

it('gives ArticleView a headings list that matches the ids in bodyHtml', function (): void {
    $id = '01K5Z9P2QH7N3X4C8V6B0M1T3D';
    $article = pageArticleFromDocument($this->author, 'Guide', [
        ($this->heading)(2, 'Getting there', $id),
        docText('Walk up from the quay.'),
        ($this->heading)(3, "What's on"),
        ($this->heading)(4, "What's on"),
        ($this->heading)(2, 'Times'),
    ]);

    $view = app(ArticleViews::class)->published($article->refresh());

    expect($view->headings)->toBe([
        ['level' => 2, 'text' => 'Getting there', 'id' => $id],
        ['level' => 3, 'text' => "What's on", 'id' => 'whats-on'],
        ['level' => 4, 'text' => "What's on", 'id' => 'whats-on-2'],
        ['level' => 2, 'text' => 'Times', 'id' => 'times'],
    ])
        ->and($view->bodyHtml)->toContain('id="'.$id.'"')
        ->and($view->bodyHtml)->toContain('id="whats-on"')
        ->and($view->bodyHtml)->toContain('id="whats-on-2"')
        ->and($view->bodyHtml)->toContain('id="times"');
});
