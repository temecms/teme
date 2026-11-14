<?php

declare(strict_types=1);

use App\Domain\Cards\LinkResolver;
use App\Domain\Comments\CommentBody;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->body = fn (array $document): string => CommentBody::html($document, app(LinkResolver::class));
    $this->linked = fn (string $href): array => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'here', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
    ]]]];
});

it('draws paragraphs, quotes, line breaks, bold, italic and code, every word escaped (I-267)', function (): void {
    expect(($this->body)(['type' => 'doc', 'content' => [
        ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quoted <b>']]]]],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Bold', 'marks' => [['type' => 'bold']]],
            ['type' => 'hardBreak'],
            ['type' => 'text', 'text' => 'x < y & "z"', 'marks' => [['type' => 'code'], ['type' => 'italic']]],
        ]],
        ['type' => 'paragraph'],
    ]]))->toBe('<blockquote><p>Quoted &lt;b&gt;</p></blockquote><p><strong>Bold</strong><br><em><code>x &lt; y &amp; &quot;z&quot;</code></em></p><p></p>');
});

it('marks a link to another site as user-generated and unendorsed, and never draws a script\'s', function (): void {
    expect(($this->body)(($this->linked)('https://example.org/a?b=1&c=2')))->toBe('<p><a href="https://example.org/a?b=1&amp;c=2" rel="ugc nofollow">here</a></p>')
        ->and(($this->body)(($this->linked)('mailto:ada@example.com')))->toBe('<p><a href="mailto:ada@example.com" rel="ugc nofollow">here</a></p>')
        ->and(($this->body)(($this->linked)('javascript:alert(1)')))->toBe('<p>here</p>');
});

it('gives a link to an item here the address it has now, and draws it as words when it has none (rule 9)', function (): void {
    $this->seed(RoleSeeder::class);
    $story = cmItem(User::factory()->create()->assignRole('author'), 'Harbour festival');

    expect(($this->body)(($this->linked)('teme:content/'.$story->id)))->toBe('<p><a href="/harbour-festival/">here</a></p>')
        ->and(($this->body)(($this->linked)('teme:content/'.cmId())))->toBe('<p>here</p>');
});
