<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Comments\Models\Comment;
use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->item = cmItem($this->editor);
    // cmSubmit()'s own key.
    $this->key = 'browser-one-0123456789abcdef';
    // A fragment has no <meta charset>, so libxml would read it as Latin-1 (B-353).
    $this->dom = fn (string $html): DOMXPath => dtDom('<meta charset="utf-8">'.$html);
});

it('gives this browser its own waiting comment on the item, drawn by the theme as only its writer sees it, never stored (I-276, D6, D7)', function (): void {
    $id = cmSubmit($this->item, 'A first word from the quay.')->commentId;

    $response = cmPending('/harbour-news/', $this->key)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $comments = $response->json('comments');
    $dom = ($this->dom)((string) $response->json('comments.0.html'));

    expect($comments)->toHaveCount(1)
        ->and($response->json('comments.0.id'))->toBe($id)
        ->and($response->json('comments.0.parentId'))->toBeNull()
        ->and($dom->query('//li[@data-teme-comment="'.$id.'"][contains(@class, "is-pending")]//*[@id="c-'.$id.'-state"]')->length)->toBe(1)
        ->and($dom->query('//a[@data-teme-reply]')->length)->toBe(0)
        ->and($response->headers->getCookies())->toBe([]);
});

it('reads a comment judged spam exactly as a held one (I-592)', function (): void {
    cmSettings(['block_list' => 'casino']);
    $id = cmSubmit($this->item, 'Visit the casino')->commentId;

    expect(Comment::query()->findOrFail($id)->status)->toBe('spam')
        ->and((string) cmPending('/harbour-news/', $this->key)->json('comments.0.html'))->toContain('Awaiting moderation.');
});

it('gives another browser\'s key nothing, and reads nothing at all for a key that isn\'t one (D6, D15)', function (): void {
    cmSubmit($this->item, 'A first word from the quay.');

    cmPending('/harbour-news/', 'browser-two-0123456789abcdef')->assertOk()->assertExactJson(['comments' => []]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    cmPending('/harbour-news/', 'short')->assertOk()->assertExactJson(['comments' => []]);
    cmPending('/harbour-news/', null)->assertOk()->assertExactJson(['comments' => []]);

    expect(DB::getQueryLog())->toBe([]);
});

it('finds the item by its address, an old one too, and never by its ID (1.7c\'s D11, D6)', function (): void {
    $id = cmSubmit($this->item, 'A first word from the quay.')->commentId;
    addrRename($this->item, 'harbour-news-today', $this->editor);

    expect(cmPending('/harbour-news-today/', $this->key)->json('comments.0.id'))->toBe($id)
        ->and(cmPending('/harbour-news/', $this->key)->json('comments.0.id'))->toBe($id);

    cmPending((string) $this->item->id, $this->key)->assertExactJson(['comments' => []]);
});

it('includes the writer\'s own comment approved since a stored page could have been drawn, as every reader sees it, and no older one (D6)', function (): void {
    $id = cmSubmit($this->item, 'A first word from the quay.')->commentId;
    cmModerate(Comment::query()->findOrFail($id), 'approved', $this->editor);
    $html = (string) cmPending('/harbour-news/', $this->key)->json('comments.0.html');

    expect($html)->toContain('data-teme-comment="'.$id.'"')->toContain('data-teme-reply="'.$id.'"')->not->toContain('Awaiting moderation.');

    $this->travel(PageCache::lifetime() + PageCache::RENDER_WINDOW + 1)->seconds();

    cmPending('/harbour-news/', $this->key)->assertExactJson(['comments' => []]);
});

it('draws a reply inside the place of the comment it\'s stored under, so the script can take the theme\'s own list of replies (D7)', function (): void {
    $top = cmComment($this->item, attributes: ['author_name' => 'Bo Reader', 'author_email' => 'bo@example.com']);
    $id = cmSubmit($this->item, 'I agree with Bo.', ['parentId' => $top->id])->commentId;
    $response = cmPending('/harbour-news/', $this->key);
    $dom = ($this->dom)((string) $response->json('comments.0.html'));

    expect($response->json('comments.0.parentId'))->toBe($top->id)
        ->and($dom->query('//li[@data-teme-comment="'.$top->id.'"]/ol[@data-teme-replies]/li[@data-teme-comment="'.$id.'"][contains(@class, "is-pending")]')->length)->toBe(1)
        ->and(trim((string) $dom->query('//li[@data-teme-comment="'.$id.'"]//*[contains(@class, "c-inreply")]')->item(0)?->textContent))->toBe('In reply to Bo Reader');
});

it('gives nothing while comments are switched off, the item is Hidden, or the address names no item (I-484, I-274)', function (Closure $change, string $path): void {
    cmSubmit($this->item, 'A first word from the quay.');
    $change($this->item);

    cmPending($path, $this->key)->assertOk()->assertExactJson(['comments' => []]);
})->with([
    'switched off' => [fn (Content $item) => cmSettings(['comments' => 'off'], 'modules'), '/harbour-news/'],
    'Hidden' => [fn (Content $item) => DB::table('contents')->where('id', $item->id)->update(['comment_status' => 'hidden']), '/harbour-news/'],
    'no item there' => [fn (Content $item) => null, '/nowhere/'],
]);

it('draws with core\'s plain partial for a theme that has none of its own (D7)', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $id = cmSubmit($this->item, 'A first word from the quay.')->commentId;

    expect(trim((string) cmPending('/harbour-news/', $this->key)->json('comments.0.html')))->toStartWith('<li id="comment-'.$id.'">');
});

it('carries nothing personal beyond what the thread shows: no email address, IP address, browser, key or key\'s hash (I-617, D17)', function (): void {
    cmSubmit($this->item, 'A first word from the quay.');
    $body = (string) cmPending('/harbour-news/', $this->key)->getContent();

    expect($body)->toContain('Ada Guest')
        ->not->toContain('ada@example.com')
        ->not->toContain('203.0.113.7')
        ->not->toContain('Mozilla')
        ->not->toContain($this->key)
        ->not->toContain(hash('sha256', $this->key));
});

it('is passed by and never stored by the page cache (D6)', function (): void {
    $folder = cacheOn();
    cmSubmit($this->item, 'A first word from the quay.');

    cmPending('/harbour-news/', $this->key)->assertOk()->assertHeaderMissing('X-Teme-Cache');

    expect(DB::table('page_cache_tags')->count())->toBe(0);

    cacheOff($folder);
});

it('finds a browser\'s own comments through an index of their own (D14)', function (): void {
    expect((string) DB::scalar("SELECT indexdef FROM pg_indexes WHERE indexname = 'comments_token'"))
        ->toContain('(commenter_token_hash, content_id)')
        ->toContain('WHERE (commenter_token_hash IS NOT NULL)');
});
