<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
    $this->item = cmItem($this->author);
    cmSettings(['per_page' => 2]);
    $this->first = cmComment($this->item);
    $this->second = cmComment($this->item);
    $this->third = cmComment($this->item);
    $this->reply = cmComment($this->item, parent: $this->third);
});

it('draws a later page of comments at its own address, in core\'s plain page when the theme has none (D2, D10)', function (): void {
    $response = addrGet('/harbour-news/comments/page/2/')->assertOk()
        ->assertHeader('X-Teme-Template', 'teme::fallback.templates.comments');
    $dom = dtDom((string) $response->getContent());

    expect((string) $dom->query('//title')->item(0)?->textContent)->toStartWith('Comments on Harbour news, page 2')->toEndWith(pageSiteName())
        ->and($dom->query('//*[@id="comment-'.$this->third->id.'"]')->length)->toBe(1)
        ->and($dom->query('//*[@id="comment-'.$this->reply->id.'"]')->length)->toBe(1)
        ->and($dom->query('//*[@id="comment-'.$this->first->id.'"]')->length)->toBe(0);
});

it('sends page 1 to the item itself, and answers 404 past the last page (D2)', function (): void {
    expect(addrLocation(addrGet('/harbour-news/comments/page/1/')->assertStatus(301)))->toBe('/harbour-news/');

    addrGet('/harbour-news/comments/page/3/')->assertNotFound();
});

it('answers a later page\'s address in its canonical form, and an old address at the item\'s new one', function (): void {
    expect(addrLocation(addrGet('/harbour-news/comments/page/2')->assertStatus(301)))->toBe('/harbour-news/comments/page/2/');

    addrRename($this->item, 'harbour-report', $this->author);

    expect(addrLocation(addrGet('/harbour-news/comments/page/2/')->assertStatus(301)))->toBe('/harbour-report/comments/page/2/');
});

it('answers 404 while comments are switched off or the item is Hidden, and still shows a closed item\'s comments (I-484, I-274)', function (): void {
    DB::table('contents')->where('id', $this->item->id)->update(['comment_status' => 'closed']);
    addrGet('/harbour-news/comments/page/2/')->assertOk();

    DB::table('contents')->where('id', $this->item->id)->update(['comment_status' => 'hidden']);
    addrGet('/harbour-news/comments/page/2/')->assertNotFound();

    DB::table('contents')->where('id', $this->item->id)->update(['comment_status' => 'open']);
    cmSettings(['comments' => 'off'], 'modules');
    addrGet('/harbour-news/comments/page/2/')->assertNotFound();
});

it('leaves an address that only looks like a page of comments to the rules it always met', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    pageArticle($editor, 'Filed under comments', categories: [typesTerm($editor, 'category', 'Comments')]);

    expect(addrLocation(addrGet('/category/comments/page/1/')->assertStatus(301)))->toBe('/category/comments/');

    addrGet('/category/comments/')->assertOk();
});

it('sends a comment\'s own address to the page its conversation is on, where its anchor lands it (I-272)', function (): void {
    expect(addrGet("/_teme/comment/{$this->first->id}")->assertStatus(302)->assertHeader('Cache-Control', 'no-store, private')->headers->get('Location'))
        ->toBe("http://localhost/harbour-news/#comment-{$this->first->id}")
        ->and(addrGet("/_teme/comment/{$this->reply->id}")->assertStatus(302)->headers->get('Location'))
        ->toBe("http://localhost/harbour-news/comments/page/2/#comment-{$this->reply->id}");
});

it('answers 404 for a comment no reader sees, one hidden with the comment above it, one on a Hidden item, and every one while comments are off (D3)', function (): void {
    $waiting = cmComment($this->item, 'pending');
    addrGet("/_teme/comment/{$waiting->id}")->assertNotFound();

    cmModerate($this->third, 'trash', $this->author);
    addrGet("/_teme/comment/{$this->reply->id}")->assertNotFound();

    DB::table('contents')->where('id', $this->item->id)->update(['comment_status' => 'hidden']);
    addrGet("/_teme/comment/{$this->first->id}")->assertNotFound();

    DB::table('contents')->where('id', $this->item->id)->update(['comment_status' => 'open']);
    cmSettings(['comments' => 'off'], 'modules');
    addrGet("/_teme/comment/{$this->first->id}")->assertNotFound();
});
