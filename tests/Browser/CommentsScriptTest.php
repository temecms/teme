<?php

declare(strict_types=1);

use App\Domain\Comments\Models\Comment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');

    // One application serves every request in the test's process: each one
    // starts afresh, as on a real server (TwoEditorsTest, EditLinkTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    $this->site = dtSite($this->author);
    $this->story = dtUrl($this->site, 'longRead');

    // The suite's first visit is its cold one (B-356): draw the story once here, so the browser's isn't.
    addrGet($this->story)->assertOk();
});

it('saves a guest\'s details in this browser only when asked, and keeps its key either way (I-276, D5)', function (): void {
    $page = visit($this->story)
        ->assertVisible('[data-teme-remember]')
        ->type('#c-text', 'A reader writes from the quay.')
        ->type('#c-name', 'Ada Reader')
        ->type('#c-email', 'ada.reader@example.com')
        ->check('remember')
        ->press('Post comment')
        ->assertSee('Your comment is awaiting moderation');

    expect(Comment::query()->where('author_email', 'ada.reader@example.com')->value('commenter_token_hash'))->toHaveLength(64);

    $page->navigate($this->story)
        ->assertValue('#c-name', 'Ada Reader')
        ->assertValue('#c-email', 'ada.reader@example.com')
        ->assertChecked('remember')
        ->assertScript("/^[A-Za-z0-9_-]{32}$/.test(localStorage.getItem('teme-commenter-key'))")
        ->uncheck('remember')
        ->type('#c-text', 'A second word, and forget me.')
        ->press('Post comment')
        ->assertSee('Your comment is awaiting moderation')
        ->navigate($this->story)
        ->assertValue('#c-name', '')
        ->assertScript("localStorage.getItem('teme-commenter') === null && localStorage.getItem('teme-commenter-key') !== null");
});

it('shows a signed-in editor the name they comment under in place of the guest fields, asking who is reading once (D3, D4)', function (): void {
    visit('/admin/login')
        ->type('email', $this->author->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin')
        ->navigate($this->story)
        ->assertVisible('[data-teme-as]')
        ->assertSeeIn('[data-teme-as]', 'Jane Doe')
        ->assertScript("document.querySelector('[data-teme-guest]').hidden")
        ->assertScript("performance.getEntriesByType('resource').filter((entry) => entry.name.includes('/_teme/session')).length === 1");
});

it('posts without leaving the page, puts the waiting comment at the end of the thread, and shows it again on the next visit (D9, D10, D13, I-276)', function (): void {
    $page = visit($this->story)
        ->type('#c-text', 'A reader writes from the quay.')
        ->type('#c-name', 'Ada Reader')
        ->type('#c-email', 'ada.reader@example.com')
        ->press('Post comment')
        ->assertSeeIn('[data-teme-status]', 'Thanks. Your comment is awaiting moderation: only you can see it until a moderator approves it.')
        ->assertPathIs($this->story)
        ->assertScript("document.querySelector('[data-teme-thread] > [data-teme-comment]:last-child').textContent.includes('A reader writes from the quay.')")
        ->assertScript("document.activeElement === document.querySelector('[data-teme-form] button[type=submit]')")
        ->assertValue('#c-text', '');

    $page->refresh()
        ->assertSeeIn('[data-teme-thread] > [data-teme-comment]:last-child', 'Awaiting moderation.');
});

it('draws the form again where it stands when something is wrong, the words kept and the summary focused (D8, 1.8b\'s D14)', function (): void {
    visit($this->story)
        ->type('#c-text', 'Words worth keeping.')
        ->press('Post comment')
        ->assertSeeIn('#respond-errors', 'Enter your name.')
        ->assertScript("document.activeElement === document.getElementById('respond-errors')")
        ->assertValue('#c-text', 'Words worth keeping.')
        ->assertPathIs($this->story)
        ->assertVisible('[data-teme-remember]');
});

it('asks nothing about a browser\'s own comments until it has posted one (D5, D6)', function (): void {
    visit($this->story)
        ->assertVisible('[data-teme-form]')
        ->assertScript("localStorage.getItem('teme-commenter-key') === null")
        ->assertScript("performance.getEntriesByType('resource').some((entry) => entry.name.includes('/_teme/comments/pending')) === false");
});

it('posts a signed-in editor\'s comment as theirs, shown at once and marked as the story\'s author (D4, I-483, I-597)', function (): void {
    visit('/admin/login')
        ->type('email', $this->author->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin')
        ->navigate($this->story)
        ->assertVisible('[data-teme-as]')
        ->type('#c-text', 'Thank you all for reading.')
        ->press('Post comment')
        ->assertSeeIn('[data-teme-status]', 'Thanks. Your comment is posted.')
        ->assertSeeIn('[data-teme-thread] > [data-teme-comment]:last-child', 'Author');

    expect(Comment::query()->orderByDesc('id')->firstOrFail()->only(['author_user_id', 'status']))
        ->toBe(['author_user_id' => $this->author->id, 'status' => 'approved']);
});

it('moves the one form under the comment answered, and back with Cancel or Escape, focus on its Reply (I-594, D11)', function (): void {
    $reply = '[data-teme-replies] [data-teme-reply]';
    $under = "document.querySelector('[data-teme-respond]').closest('[data-teme-comment]') === document.querySelector('[data-teme-replies] [data-teme-reply]').closest('[data-teme-comment]')";
    $home = "document.querySelector('[data-teme-respond]').closest('[data-teme-comment]') === null";
    $onReply = "document.activeElement === document.querySelector('[data-teme-replies] [data-teme-reply]')";

    $page = visit($this->story)
        ->click($reply)
        ->assertScript($under)
        ->assertSeeIn('#respond-h', 'Reply to Jane Doe')
        ->assertSeeIn('[data-teme-form] button[type=submit]', 'Post reply')
        ->assertScript("document.activeElement === document.getElementById('c-text')")
        ->assertScript("document.querySelector('[data-teme-replying]').hidden")
        ->keys('#c-text', 'Escape')
        ->assertScript($home)
        ->assertSeeIn('#respond-h', 'Leave a comment')
        ->assertScript($onReply);

    $page->click($reply)
        ->click('Cancel reply')
        ->assertScript($home)
        ->assertScript($onReply);
});

it('says where a reply to a comment at the deepest level will go, and puts it there (I-609, D10)', function (): void {
    cmSettings(['thread_depth' => 2]);

    visit($this->story)
        ->click('[data-teme-replies] [data-teme-reply]')
        ->assertSeeIn('[data-teme-replying]', 'Replies stop nesting here, so yours will appear at the end of this conversation.')
        ->type('#c-text', 'Beside the author’s reply.')
        ->type('#c-name', 'Ada Reader')
        ->type('#c-email', 'ada.reader@example.com')
        ->press('Post reply')
        ->assertSeeIn('[data-teme-status]', 'Thanks. Your reply is awaiting moderation')
        ->assertScript("document.querySelectorAll('[data-teme-thread] > [data-teme-comment] > [data-teme-replies] > [data-teme-comment]').length === 2");
});

it('posts a reply with Ctrl and Enter, in a list of replies the theme drew, and returns focus to Reply (D7, D12, D13)', function (): void {
    visit($this->story)
        ->click('[data-teme-replies] [data-teme-reply]')
        ->type('#c-text', 'A word for the author.')
        ->type('#c-name', 'Ada Reader')
        ->type('#c-email', 'ada.reader@example.com')
        ->keys('#c-text', 'Control+Enter')
        ->assertSeeIn('[data-teme-status]', 'Thanks. Your reply is awaiting moderation')
        ->assertScript("document.querySelector('[data-teme-replies] [data-teme-reply]').closest('[data-teme-comment]').querySelector(':scope > [data-teme-replies] > [data-teme-comment]') !== null")
        ->assertScript("document.activeElement === document.querySelector('[data-teme-replies] [data-teme-reply]')")
        ->assertScript("document.querySelector('[data-teme-respond]').closest('[data-teme-comment]') === null");
});

it('passes axe with the form moved under a comment, and with a reply just posted in place, in light and dark (I-594)', function (string $scheme): void {
    designPublish(['theme.default' => ['color_scheme' => $scheme]]);

    visit($this->story)
        ->click('[data-teme-replies] [data-teme-reply]')
        ->assertNoAccessibilityIssues(3)
        ->type('#c-text', 'A word for the author.')
        ->type('#c-name', 'Ada Reader')
        ->type('#c-email', 'ada.reader@example.com')
        ->press('Post reply')
        ->assertSeeIn('[data-teme-status]', 'Thanks. Your reply is awaiting moderation')
        ->assertNoAccessibilityIssues(3);
})->with(['light', 'dark']);

it('adds Edit to each comment an editor can edit, naming its writer, and to no ping or tombstone, on both pages of the thread, passing axe (I-651, D5 to D7)', function (string $scheme): void {
    designPublish(['theme.default' => ['color_scheme' => $scheme]]);
    $ada = (string) Comment::query()->where('author_name', 'Ada Guest')->value('id');
    $jane = (string) Comment::query()->where('author_user_id', $this->author->id)->value('id');

    visit('/admin/login')
        ->type('email', $this->author->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin')
        ->navigate($this->story)
        ->assertScript("document.querySelectorAll('[data-teme-edit]').length === 2")
        ->assertScript("document.querySelector('[data-teme-comment=\"{$ada}\"] > article [data-teme-edit]').textContent === 'Edit Ada Guest’s comment'")
        ->assertAttribute("[data-teme-comment=\"{$ada}\"] > article [data-teme-edit]", 'href', '/admin/comments?comment='.$ada)
        ->assertScript("document.querySelector('[data-teme-comment=\"{$jane}\"] [data-teme-edit]').textContent === 'Edit Jane Doe’s comment'")
        ->assertScript("document.querySelector('[data-teme-comment] > .c-ping').closest('[data-teme-comment]').querySelector('[data-teme-edit]') === null")
        ->assertNoAccessibilityIssues(3)
        ->navigate($this->site['commentsPage'])
        ->assertScript("document.querySelectorAll('[data-teme-edit]').length === 2")
        ->assertScript("[...document.querySelectorAll('[data-teme-edit]')].every((link) => link.closest('[data-teme-comment]').querySelector(':scope > .c-removed') === null)");
})->with(['light', 'dark']);

it('gives Edit on a closed thread, where no form is drawn, and sets a writer\'s name as text, never as markup or a replacement (I-678, D3, D6)', function (): void {
    $item = cmItem($this->author, 'Harbour news');
    cmComment($item, attributes: ['author_name' => 'Zed <b>$& O’Neil']);
    DB::table('contents')->where('id', $item->id)->update(['comment_status' => 'closed']);
    addrGet('/harbour-news/')->assertOk();
    $expected = json_encode('Edit Zed <b>$& O’Neil’s comment', JSON_UNESCAPED_UNICODE);

    visit('/admin/login')
        ->type('email', $this->author->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin')
        ->navigate('/harbour-news/')
        ->assertSee('Comments are closed.')
        ->assertScript("document.querySelectorAll('[data-teme-reply]').length === 0")
        ->assertScript("document.querySelector('[data-teme-comment] [data-teme-edit]').textContent === {$expected}")
        ->assertScript("document.querySelector('[data-teme-actions]').hidden === false")
        ->assertScript("document.querySelector('[data-teme-edit] b') === null");
});

it('gives Edit to a later page of the comments, where the path is not the item\'s own (D2)', function (): void {
    visit('/admin/login')
        ->type('email', $this->author->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin')
        ->navigate($this->site['commentsPage'])
        ->assertScript("document.querySelectorAll('[data-teme-comment] [data-teme-edit]').length === 2")
        ->assertScript("[...document.querySelectorAll('[data-teme-edit]')].some((link) => link.textContent === 'Edit Bo Reader’s comment')");
});

it('adds no Edit for a guest, and asks nothing from the server beyond the page itself (D1, D5)', function (): void {
    visit($this->story)
        ->assertVisible('[data-teme-form]')
        ->assertScript("document.querySelectorAll('[data-teme-edit]').length === 0")
        ->assertScript("document.querySelectorAll('[data-teme-actions]:not([hidden])').length === document.querySelectorAll('[data-teme-actions] [data-teme-reply]').length")
        ->assertScript("performance.getEntriesByType('resource').filter((entry) => entry.name.includes('/_teme/')).length === 0");
});

it('places Edit by hooks alone in a theme without an actions line: beside the comments that offer Reply only, never on a ping or a tombstone (I-678, D5)', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/hookless')]]);
    themeActivate('hookless');
    $item = cmItem($this->author, 'Quay notes');
    $plain = cmComment($item, attributes: ['author_name' => 'Ada Guest']);
    $gone = cmComment($item, 'removed', attributes: ['author_name' => null, 'author_email' => null, 'body' => null]);
    cmComment($item, parent: $gone, attributes: ['author_name' => 'Cy Reader']);
    cmComment($item, attributes: ['kind' => 'pingback', 'author_name' => 'Tide Watch', 'author_url' => 'https://tides.example/post', 'author_email' => null]);
    addrGet('/quay-notes/')->assertOk();

    visit('/admin/login')
        ->type('email', $this->author->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin')
        ->navigate('/quay-notes/')
        ->assertScript("document.querySelectorAll('[data-teme-edit]').length === 2")
        ->assertScript("document.querySelector('[data-teme-comment=\"{$plain->id}\"] [data-teme-edit]').textContent === 'Edit comment'")
        ->assertScript("[...document.querySelectorAll('[data-teme-edit]')].every((link) => link.closest('p').closest('[data-teme-comment]') === link.closest('[data-teme-comment]') && link.closest('[data-teme-comment]').querySelector(':scope > .c-removed') === null)");
});

it('recounts the heading and hides the empty line after a comment is posted at once, and gives the new comment its Edit (I-639, I-692, D8)', function (): void {
    cmItem($this->author, 'Quiet corner');
    addrGet('/quiet-corner/')->assertOk();

    visit('/admin/login')
        ->type('email', $this->author->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin')
        ->navigate('/quiet-corner/')
        ->assertScript("document.getElementById('comments-h').textContent === 'Comments'")
        ->assertVisible('[data-teme-empty]')
        ->type('#c-text', 'Thank you all for reading.')
        ->press('Post comment')
        ->assertSeeIn('[data-teme-status]', 'Thanks. Your comment is posted.')
        ->assertScript("document.getElementById('comments-h').textContent === '1 comment'")
        ->assertScript("getComputedStyle(document.querySelector('[data-teme-empty]')).display === 'none'")
        ->assertScript("document.querySelectorAll('[data-teme-comment] [data-teme-edit]').length === 1")
        ->type('#c-text', 'A second word for the harbour.')
        ->press('Post comment')
        ->assertScript("document.getElementById('comments-h').textContent === '2 comments'")
        ->assertScript("document.querySelectorAll('[data-teme-comment] [data-teme-edit]').length === 2");
});

it('leaves the heading and the empty line as everyone sees them after a first comment that waits (I-639, D8)', function (): void {
    cmItem($this->author, 'Quiet corner');
    addrGet('/quiet-corner/')->assertOk();

    visit('/quiet-corner/')
        ->type('#c-text', 'A reader writes from the quay.')
        ->type('#c-name', 'Ada Reader')
        ->type('#c-email', 'ada.reader@example.com')
        ->press('Post comment')
        ->assertSeeIn('[data-teme-status]', 'Thanks. Your comment is awaiting moderation')
        ->assertScript("document.querySelector('[data-teme-thread] [data-teme-comment]') !== null")
        ->assertScript("document.getElementById('comments-h').textContent === 'Comments'")
        ->assertScript("document.querySelector('[data-teme-empty]').hidden === false");
});
