<?php

declare(strict_types=1);

use App\Domain\Site\ArticleViews;
use App\Domain\Site\ViewModels\Block;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->author = User::factory()->create()->assignRole('author');
});

it('lists the body\'s top-level blocks with their type, attributes and HTML', function (): void {
    $article = pageArticleFromDocument($this->author, 'Sections', [
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'First']]],
        docText('Opening words.'),
        ['type' => 'horizontalRule'],
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Second']]],
        ['type' => 'callout', 'attrs' => ['id' => '01J0000000000000000000000A', 'tone' => 'tip', 'text' => 'Mind the gap.']],
    ]);

    $blocks = app(ArticleViews::class)->published($article->refresh())->blocks;

    expect(array_map(fn (Block $block): string => $block->type, $blocks))->toBe(['heading', 'paragraph', 'horizontalRule', 'heading', 'callout'])
        ->and($blocks[0]->attrs['level'])->toBe(2)
        ->and($blocks[0]->html)->toBe('<h2>First</h2>')
        ->and($blocks[1]->html)->toBe('<p>Opening words.</p>')
        ->and($blocks[4]->html)->toContain('card-callout')->toContain('Mind the gap.');
});

it('draws the same HTML as bodyHtml, which stays', function (): void {
    $article = pageArticleFromDocument($this->author, 'Sections', [
        docText('One'),
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Two']]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [docText('x')]]]],
        ['type' => 'callout', 'attrs' => ['id' => '01J0000000000000000000000A', 'tone' => 'tip', 'text' => 'Three']],
    ]);

    $view = app(ArticleViews::class)->published($article->refresh());

    expect(implode('', array_map(fn (Block $block): string => $block->html, $view->blocks)))->toBe($view->bodyHtml);
});

it('has no blocks for an empty body', function (): void {
    $article = pageArticleFromDocument($this->author, 'Empty', []);

    expect(app(ArticleViews::class)->published($article->refresh())->blocks)->toBe([]);
});
