<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Site\Document\LinkRelation;
use App\Domain\Site\Document\LinkTag;
use App\Domain\Site\Document\Precedence;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->news = headTerm('category', 'News', 'Stories from the harbour and the town.');

    foreach (range(1, 11) as $i) {
        headPost($this->author, "Story {$i}", [], [$this->news]);
    }

    $this->folder = null;
    $this->links = function (string $uri, int $status = 200): array {
        $dom = headDom($uri, $status);

        return [
            'canonical' => headValue($dom, '//head/link[@rel="canonical"]/@href'),
            'canonicals' => (int) $dom->evaluate('count(//head/link[@rel="canonical"])'),
            'prev' => headValue($dom, '//head/link[@rel="prev"]/@href'),
            'next' => headValue($dom, '//head/link[@rel="next"]/@href'),
            'description' => headValue($dom, '//head/meta[@name="description"]/@content'),
            'descriptions' => (int) $dom->evaluate('count(//head/meta[@name="description"])'),
        ];
    };
});

afterEach(function (): void {
    if ($this->folder !== null) {
        cacheOff($this->folder);
    }
});

it('points every listed page to itself: an item, the home page, an archive, and page 2 of it to page 2 (I-292)', function (string $uri): void {
    expect(($this->links)($uri))->toMatchArray(['canonical' => 'http://localhost'.$uri, 'canonicals' => 1]);
})->with(['/story-1/', '/', '/category/news/', '/category/news/page/2/']);

it('prints no canonical on a hidden item, a search, a 404 or core\'s reply page (I-292)', function (string $case): void {
    headPost($this->author, 'Hidden one', [BuiltInFields::NOINDEX => true]);
    $comment = cmComment(cmItem($this->author, 'Harbour news'), attributes: ['author_name' => 'Bo Reader']);

    $links = match ($case) {
        'a hidden item' => ($this->links)('/hidden-one/'),
        'a search' => ($this->links)('/?s=story'),
        'a 404' => ($this->links)('/no-such-page/', 404),
        default => ($this->links)("/_teme/comment/{$comment->id}/reply"),
    };

    expect($links['canonicals'])->toBe(0);
})->with(['a hidden item', 'a search', 'a 404', 'core\'s reply page']);

it('points to the item an override names, by its live address, purged when it moves, and to itself once that item has none (I-292, rule 9)', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $original = headPost($this->author, 'Original story');
    headPost($this->author, 'Copied story', [BuiltInFields::CANONICAL => ['item' => $original->id]]);
    $this->folder = cacheOn();

    expect(($this->links)('/copied-story/')['canonical'])->toBe('http://localhost/original-story/');

    addrRename($original, 'first-telling', $editor);

    expect(cacheKept('/copied-story/'))->toBe([])
        ->and(($this->links)('/copied-story/')['canonical'])->toBe('http://localhost/first-telling/');

    pageTrash($original->refresh(), $editor);

    expect(($this->links)('/copied-story/')['canonical'])->toBe('http://localhost/copied-story/');
});

it('points to another site\'s address as it was stored, and to itself when the override names the item itself', function (): void {
    headPost($this->author, 'Syndicated', [BuiltInFields::CANONICAL => ['address' => 'https://ledger.example/first/?ref=a&b=1']]);
    $self = headPost($this->author, 'Self');
    headPost($this->author, 'Self again', [BuiltInFields::CANONICAL => ['item' => $self->id]]);

    $html = (string) addrGet('/syndicated/')->assertOk()->getContent();

    expect(($this->links)('/syndicated/')['canonical'])->toBe('https://ledger.example/first/?ref=a&b=1')
        ->and($html)->toContain('<link rel="canonical" href="https://ledger.example/first/?ref=a&amp;b=1">')
        ->and(($this->links)('/self-again/')['canonical'])->toBe('http://localhost/self/');
});

it('writes prev and next on an archive\'s pages, and none on a search\'s (I-290)', function (): void {
    expect(($this->links)('/category/news/'))->toMatchArray(['prev' => '', 'next' => 'http://localhost/category/news/page/2/'])
        ->and(($this->links)('/category/news/page/2/'))->toMatchArray(['prev' => 'http://localhost/category/news/', 'next' => ''])
        ->and(($this->links)('/?s=story'))->toMatchArray(['prev' => '', 'next' => '']);
});

it('describes an item by its meta description, then its excerpt, then nothing; the home page by its tagline; a term\'s archive by its description (I-290)', function (string $case, string $uri, string $expected): void {
    cmSettings(['tagline' => 'News of the city'], 'general');
    headPost($this->author, 'Described', [BuiltInFields::META_DESCRIPTION => 'What the harbour board decided.', BuiltInFields::EXCERPT => 'Not this.']);
    headPost($this->author, 'Excerpted', [BuiltInFields::EXCERPT => 'The board met on Tuesday.']);

    expect(($this->links)($uri))->toMatchArray(['description' => $expected, 'descriptions' => $expected === '' ? 0 : 1]);
})->with([
    'a meta description' => ['a meta description', '/described/', 'What the harbour board decided.'],
    'an excerpt' => ['an excerpt', '/excerpted/', 'The board met on Tuesday.'],
    'neither' => ['neither', '/story-1/', ''],
    'the home page' => ['the home page', '/', 'News of the city'],
    'a term\'s archive' => ['a term\'s archive', '/category/news/', 'Stories from the harbour and the town.'],
    'a date archive' => ['a date archive', '/'.date('Y').'/', ''],
]);

it('writes the description before its links, and gives way to a plugin\'s canonical, first by precedence (I-228)', function (): void {
    headPost($this->author, 'Described', [BuiltInFields::META_DESCRIPTION => 'What the harbour board decided.']);
    $html = (string) addrGet('/described/')->assertOk()->getContent();
    $head = substr($html, 0, (int) strpos($html, '</head>'));

    expect((int) strpos($head, '<meta name="description"'))->toBeGreaterThan(0)
        ->and((int) strpos($head, '<meta name="description"'))->toBeLessThan((int) strpos($head, '<link rel="canonical"'));

    headWith(Precedence::Plugin, [new LinkTag(LinkRelation::Canonical, 'https://example.test/elsewhere/')]);

    expect(($this->links)('/described/'))->toMatchArray(['canonical' => 'https://example.test/elsewhere/', 'canonicals' => 1]);
});
