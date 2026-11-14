<?php

declare(strict_types=1);

use App\Domain\Cache\SignedInMarker;
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
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->item = cmItem($this->author);
    // As core's script posts: from the page's own origin, asking for JSON.
    $this->json = ['Sec-Fetch-Site' => 'same-origin', 'Accept' => 'application/json'];
    // A fragment has no <meta charset>, so libxml would read it as Latin-1 (B-353).
    $this->dom = fn (string $html): DOMXPath => dtDom('<meta charset="utf-8">'.$html);
});

it('answers core\'s script in JSON: a guest\'s first comment held, its place drawn as only its writer sees it, its key kept as a hash, never stored (D8, I-593)', function (): void {
    $response = cmPost($this->item, ['text' => 'A first word from the quay.', 'token' => 'browser-one-0123456789abcdef'], $this->json)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $id = Comment::query()->sole()->id;

    expect($response->json())->toMatchArray([
        'outcome' => 'waiting',
        'message' => 'Thanks. Your comment is awaiting moderation: only you can see it until a moderator approves it.',
        'form' => null,
    ])
        ->and($response->json('comment.id'))->toBe($id)
        ->and($response->json('comment.parentId'))->toBeNull()
        ->and(($this->dom)((string) $response->json('comment.html'))->query('//li[@data-teme-comment="'.$id.'"][contains(@class, "is-pending")]')->length)->toBe(1)
        ->and(Comment::query()->sole()->commenter_token_hash)->toBe(hash('sha256', 'browser-one-0123456789abcdef'));
});

it('answers a comment shown at once as posted, drawn as every reader will see it, its Reply included (D8, I-483)', function (): void {
    // The item's own author looks after its comments (I-483).
    $this->actingAs($this->author);

    $response = cmPost($this->item, ['text' => 'The author answers.'], $this->json, [SignedInMarker::COOKIE => '1'])->assertOk();
    $id = Comment::query()->sole()->id;

    expect($response->json('outcome'))->toBe('posted')
        ->and($response->json('message'))->toBe('Thanks. Your comment is posted.')
        ->and(($this->dom)((string) $response->json('comment.html'))->query('//li[@data-teme-comment="'.$id.'"][not(contains(@class, "is-pending"))]//a[@data-teme-reply="'.$id.'"]')->length)->toBe(1);
});

it('answers a reply inside the place of the comment it\'s stored under, beside the one answered at the deepest level (D8, 1.8b\'s D11)', function (): void {
    cmSettings(['thread_depth' => 2]);
    $top = cmComment($this->item, attributes: ['author_name' => 'Bo Reader', 'author_email' => 'bo@example.com']);
    $deep = cmComment($this->item, parent: $top, attributes: ['author_name' => 'Cy Reader', 'author_email' => 'cy@example.com']);

    $response = cmPost($this->item, ['text' => 'Me too, Cy.', 'parent' => $deep->id], $this->json)->assertOk();
    $id = (string) Comment::query()->where('parent_id', $top->id)->where('id', '!=', $deep->id)->value('id');

    expect($response->json('message'))->toBe('Thanks. Your reply is awaiting moderation: only you can see it until a moderator approves it.')
        ->and($response->json('comment.parentId'))->toBe($top->id)
        ->and(($this->dom)((string) $response->json('comment.html'))->query('//li[@data-teme-comment="'.$top->id.'"]/ol[@data-teme-replies]/li[@data-teme-comment="'.$id.'"]')->length)->toBe(1);
});

it('answers spam, and a filled honeypot, exactly as held, the honeypot with no comment to place (I-592, 1.8b\'s D8)', function (): void {
    cmSettings(['block_list' => 'casino']);

    $spam = cmPost($this->item, ['text' => 'Visit the casino'], $this->json)->assertOk();
    $trapped = cmPost($this->item, ['text' => 'Another word', 'subject' => 'Buy now'], $this->json)->assertOk();

    expect($spam->json('outcome'))->toBe('waiting')
        ->and((string) $spam->json('comment.html'))->toContain('Awaiting moderation.')
        ->and($trapped->json())->toBe([
            'outcome' => 'waiting',
            'message' => 'Thanks. Your comment is awaiting moderation: only you can see it until a moderator approves it.',
            'comment' => null,
            'form' => null,
        ]);
});

it('answers a form with errors with core\'s form drawn again for the thread: what was wrong first, the words kept (D8, 1.8b\'s D14)', function (): void {
    $response = cmPost($this->item, ['text' => 'Hello <b>', 'name' => '', 'email' => 'not an address'], $this->json)->assertStatus(422);
    $form = ($this->dom)((string) $response->json('form'));

    expect($response->json('outcome'))->toBe('invalid')
        ->and($response->json('comment'))->toBeNull()
        ->and($form->query('//div[@data-teme-respond]/h3[@id="respond-h"]')->length)->toBe(1)
        ->and($form->query('//form[@aria-labelledby="respond-h"]//*[@id="respond-errors"][not(@autofocus)]//a[@href="#c-name"]')->length)->toBe(1)
        ->and((string) $form->query('//textarea[@name="text"]')->item(0)?->textContent)->toBe('Hello <b>')
        ->and($form->query('//input[@name="email"][@value="not an address"]')->length)->toBe(1)
        ->and(Comment::query()->count())->toBe(0);
});

it('answers the rate limit 429, with the form again and its wait first (D8)', function (): void {
    foreach (range(1, 5) as $n) {
        cmPost($this->item, ['text' => "Comment {$n}", 'email' => "reader{$n}@example.com"], $this->json)->assertOk();
    }

    $response = cmPost($this->item, ['text' => 'One more', 'email' => 'reader6@example.com'], $this->json)->assertStatus(429);

    expect($response->json('outcome'))->toBe('invalid')
        ->and((string) $response->json('form'))->toContain('Try again in')->toContain('One more');
});

it('keeps the comment answered in the form drawn again while it can still be answered, and lets the reply go as a comment when it can\'t (D8)', function (): void {
    $top = cmComment($this->item, attributes: ['author_name' => 'Bo Reader', 'author_email' => 'bo@example.com']);

    $kept = cmPost($this->item, ['text' => 'I agree.', 'parent' => $top->id, 'name' => ''], $this->json)->assertStatus(422);

    expect(($this->dom)((string) $kept->json('form'))->query('//input[@name="parent"][@value="'.$top->id.'"]')->length)->toBe(1);

    cmModerate($top, 'trash', $this->author);
    $dropped = ($this->dom)((string) cmPost($this->item, ['text' => 'I agree.', 'parent' => $top->id], $this->json)->assertStatus(422)->json('form'));

    expect($dropped->query('//input[@name="parent"][@value=""]')->length)->toBe(1)
        ->and(trim((string) $dropped->query('//*[@id="respond-errors"]//a[@href="#respond-h"]')->item(0)?->textContent))->toBe('The comment you’re replying to isn’t there to reply to.');
});

it('answers a form refused while the reader wrote with the form again, the reason first and the words kept (D8, I-593)', function (Closure $close, string $reason): void {
    $close($this->item);

    $response = cmPost($this->item, ['text' => 'My careful reply'], $this->json)->assertStatus(422);
    $form = ($this->dom)((string) $response->json('form'));

    expect($response->json('outcome'))->toBe('refused')
        ->and($response->json('message'))->toBe($reason)
        ->and(trim((string) $form->query('//*[@id="respond-errors"]//a[@href="#c-text"]')->item(0)?->textContent))->toBe($reason)
        ->and((string) $form->query('//textarea[@name="text"]')->item(0)?->textContent)->toBe('My careful reply');
})->with([
    'switched off' => [fn (Content $item) => cmSettings(['comments' => 'off'], 'modules'), 'Comments are switched off.'],
    'closed' => [fn (Content $item) => DB::table('contents')->where('id', $item->id)->update(['comment_status' => 'closed']), 'Comments here are closed.'],
]);

it('answers a form whose address names no item with the reason alone, and the guard\'s refusal in JSON (D8, 1.8b\'s D7)', function (): void {
    cmPost($this->item, ['path' => '/nowhere/', 'text' => 'Lost words'], $this->json)->assertStatus(422)
        ->assertExactJson(['outcome' => 'refused', 'message' => 'This page takes no comments.', 'comment' => null, 'form' => null]);

    cmPost($this->item, ['text' => 'Forged words'], ['Sec-Fetch-Site' => 'cross-site', 'Accept' => 'application/json'])->assertForbidden()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('outcome', 'refused')
        ->assertJsonPath('message', 'This form came from another site, so your comment wasn’t posted. To comment, go back to the story and use the form there.');

    expect(Comment::query()->count())->toBe(0);
});
