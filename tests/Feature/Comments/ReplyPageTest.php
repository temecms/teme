<?php

declare(strict_types=1);

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
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->item = cmItem($this->author);
    $this->top = cmComment($this->item, attributes: ['author_name' => 'Bo Reader']);
});

it('draws the reply page without JavaScript: the comment answered above a form of its own, never stored or indexed (I-594)', function (): void {
    $response = addrGet("/_teme/comment/{$this->top->id}/reply")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $dom = dtDom((string) $response->getContent());

    expect(trim((string) $dom->query('//h1[@id="page-h"]')->item(0)?->textContent))->toBe('Reply to Bo Reader')
        ->and((string) $dom->query('//title')->item(0)?->textContent)->toStartWith('Reply to Bo Reader')
        ->and($dom->query('//meta[@name="robots"][@content="noindex, nofollow"]')->length)->toBe(1)
        ->and($dom->query('//form[@aria-labelledby="page-h"]//input[@name="parent"][@value="'.$this->top->id.'"]')->length)->toBe(1)
        ->and((string) $response->getContent())->toContain('On “<a href="/harbour-news/">Harbour news</a>”')->toContain('href="/harbour-news/#comments"');
});

it('says a reply to a comment at the deepest level will go at the end of its conversation, and says nothing above it (I-272, D11)', function (): void {
    cmSettings(['thread_depth' => 2]);
    $deep = cmComment($this->item, parent: $this->top);

    $hint = fn (string $id): int => dtDom((string) addrGet("/_teme/comment/{$id}/reply")->assertOk()->getContent())
        ->query('//p[normalize-space(.)="Replies stop nesting here, so yours will appear at the end of this conversation."]')->length;

    expect($hint($deep->id))->toBe(1)
        ->and($hint($this->top->id))->toBe(0);
});

it('answers 404 for a comment that takes no reply (I-484, I-274, I-277)', function (Closure $change): void {
    addrGet('/_teme/comment/'.$change($this->item, $this->top).'/reply')->assertNotFound();
})->with([
    'waiting' => [fn (Content $item, Comment $top): string => cmComment($item, 'pending')->id],
    'a ping' => [fn (Content $item, Comment $top): string => cmComment($item, attributes: ['kind' => 'trackback', 'author_email' => null])->id],
    'on a closed item' => [function (Content $item, Comment $top): string {
        DB::table('contents')->where('id', $item->id)->update(['comment_status' => 'closed']);

        return $top->id;
    }],
    'while comments are off' => [function (Content $item, Comment $top): string {
        cmSettings(['comments' => 'off'], 'modules');

        return $top->id;
    }],
]);
