<?php

declare(strict_types=1);

use App\Domain\Comments\Models\Comment;
use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->item = cmItem($this->author);
    $this->h1 = fn ($response): string => trim((string) dtDom((string) $response->getContent())->query('//h1')->item(0)?->textContent);
});

it('answers a guest\'s first comment as awaiting moderation, never stored by any cache, with what they wrote (I-593)', function (): void {
    $response = cmPost($this->item, ['text' => "Lovely piece.\nSee https://example.org"])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
    $dom = dtDom((string) $response->getContent());

    expect(($this->h1)($response))->toBe('Your comment is awaiting moderation')
        ->and((string) $response->getContent())->toContain('Thanks, Ada. It will appear under “<a href="/harbour-news/">Harbour news</a>” once a moderator approves it. Until then it won’t show there, even to you.')
        ->and($dom->query('//meta[@name="robots"][@content="noindex, nofollow"]')->length)->toBe(1)
        ->and($dom->query('//a[@rel="ugc nofollow"][@href="https://example.org"]')->length)->toBe(1)
        ->and(Comment::query()->sole()->status)->toBe('pending');
});

it('answers a comment approved at once as posted, with its own address to see it by', function (): void {
    cmSettings(['approve_returning' => false]);

    $response = cmPost($this->item)->assertOk();

    expect(($this->h1)($response))->toBe('Your comment is posted')
        ->and((string) $response->getContent())
        ->toContain('href="/_teme/comment/'.Comment::query()->sole()->id.'"')
        ->toContain('href="/harbour-news/#comments"');
});

it('answers a comment judged spam, and a filled honeypot, exactly as one held, keeping and counting nothing for the honeypot (I-592, D8)', function (): void {
    cmSettings(['block_list' => 'casino']);

    $spam = cmPost($this->item, ['text' => 'Visit the casino'])->assertOk();
    $trapped = cmPost($this->item, ['text' => 'Another word', 'subject' => 'Buy now'])->assertOk();

    expect(($this->h1)($spam))->toBe('Your comment is awaiting moderation')
        ->and(($this->h1)($trapped))->toBe('Your comment is awaiting moderation')
        ->and(Comment::query()->pluck('status')->all())->toBe(['spam'])
        ->and(RateLimiter::attempts('comments:ip:127.0.0.1'))->toBe(1);
});

it('keeps the reader\'s words when comments were switched off or closed while they wrote (I-593)', function (Closure $close, string $reason): void {
    $close($this->item);

    $response = cmPost($this->item, ['text' => 'My careful reply'])->assertStatus(422);

    expect(($this->h1)($response))->toBe('Your comment wasn’t posted')
        ->and((string) $response->getContent())->toContain($reason)->toContain('Here’s what you wrote, so you can keep a copy.')
        ->and((string) dtDom((string) $response->getContent())->query('//textarea[@readonly]')->item(0)?->textContent)->toBe('My careful reply')
        ->and(Comment::query()->count())->toBe(0);
})->with([
    'switched off' => [fn (Content $item) => cmSettings(['comments' => 'off'], 'modules'), 'Comments are switched off.'],
    'closed' => [fn (Content $item) => DB::table('contents')->where('id', $item->id)->update(['comment_status' => 'closed']), 'Comments here are closed.'],
]);

it('draws the form again with the reader\'s words and the drawn errors, and answers the rate limit 429 (D14)', function (): void {
    $response = cmPost($this->item, ['text' => 'Hello', 'name' => '', 'email' => 'not an address'])->assertStatus(422);
    $dom = dtDom((string) $response->getContent());

    expect((string) $dom->query('//title')->item(0)?->textContent)->toStartWith('Error: Leave a comment')
        ->and(trim((string) $dom->query('//*[@id="respond-errors"]//a[@href="#c-name"]')->item(0)?->textContent))->toBe('Enter your name.')
        ->and(trim((string) $dom->query('//*[@id="respond-errors"]//a[@href="#c-email"]')->item(0)?->textContent))->toBe('Enter an email address like name@example.com.')
        ->and((string) $dom->query('//textarea[@name="text"]')->item(0)?->textContent)->toBe('Hello');

    foreach (range(1, 5) as $n) {
        cmPost($this->item, ['text' => "Comment {$n}", 'email' => "reader{$n}@example.com"])->assertOk();
    }

    cmPost($this->item, ['text' => 'One more', 'email' => 'reader6@example.com'])->assertStatus(429)->assertSee('Try again in', false);
});

it('answers a guest where only signed-in people may comment as not posted, writing nothing (question 2)', function (): void {
    cmSettings(['signed_in_only' => true]);

    cmPost($this->item, ['text' => 'A guest writes'])->assertForbidden()->assertSee('Sign in to comment here.', false)->assertSee('A guest writes', false);

    expect(Comment::query()->count())->toBe(0);
});

it('answers a form whose address shows no item as not posted, the words kept', function (): void {
    cmPost($this->item, ['path' => '/nowhere/', 'text' => 'Lost words'])->assertStatus(422)
        ->assertSee('This page takes no comments.', false)->assertSee('Lost words', false);
});

it('takes a reply, and answers one to a comment taken down meanwhile with a form that posts it as a comment of its own', function (): void {
    $top = cmComment($this->item, attributes: ['author_name' => 'Bo Reader']);

    cmPost($this->item, ['text' => 'I agree.', 'parent' => $top->id])->assertOk();

    expect(Comment::query()->where('parent_id', $top->id)->count())->toBe(1);

    cmModerate($top, 'trash', $this->author);
    $response = cmPost($this->item, ['text' => 'Me too.', 'parent' => $top->id])->assertStatus(422);
    $dom = dtDom((string) $response->getContent());

    expect(($this->h1)($response))->toBe('Leave a comment')
        ->and(trim((string) $dom->query('//*[@id="respond-errors"]//a[@href="#page-h"]')->item(0)?->textContent))->toBe('The comment you’re replying to isn’t there to reply to.')
        ->and($dom->query('//input[@name="parent"][@value=""]')->length)->toBe(1);
});
