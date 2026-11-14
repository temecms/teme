<?php

declare(strict_types=1);

use App\Domain\Comments\Models\Comment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (TwoEditorsTest): the
    // warm-up below signs in through actingAs(), and the browser must not
    // inherit it.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->item = cmItem($this->editor, 'Harbour news');
    $this->held = cmNext($this->item, 'pending', attributes: [
        'author_name' => 'Ada Guest',
        'ip' => '203.0.113.7',
        'moderation' => ['by' => 'Teme', 'status' => 'pending', 'reason' => 'Held: a first comment, or the first from this browser.', 'signals' => ['rule' => 'first-comment']],
    ]);
    $this->shown = cmNext($this->item, attributes: ['author_name' => 'Bola Ade', 'author_email' => 'bola@example.com']);
    $this->spam = cmNext($this->item, 'spam', attributes: ['author_name' => 'Cheap Pills', 'author_email' => 'pills@example.com']);
    $this->ping = cmNext($this->item, attributes: ['kind' => 'pingback', 'author_name' => 'Harbour Weekly', 'author_email' => null, 'author_url' => 'https://weekly.example/post']);

    // Warm the screen before the browser's first visit (I-638, B-356).
    $this->actingAs($this->editor)->get(route('comments.index'))->assertOk();
});

it('passes axe on the Comments screen and its drawer, every target 24 pixels or more, in the light and dark schemes (D24, D26)', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)
        ->navigate('/admin/comments?view=all')
        ->assertSee('Readers’ comments, newest first.')
        ->assertSee('Pingback')
        ->assertNoAccessibilityIssues(3);

    $list = cmSmallTargets($page);

    $page->click('[aria-label="Actions for the comment by Bola Ade"] [data-action="more"]')
        ->click('[role="menu"] >> text="Details"')
        ->assertSee('Comment by Bola Ade')
        // The Actions menu fades out as the drawer opens; axe reads a fading item's colours as the page's own.
        ->assertNotPresent('[role="menu"]')
        ->assertNoAccessibilityIssues(3);

    $drawer = cmSmallTargets($page);

    expect($list['small'])->toBe([])
        ->and($list['checked'])->toBeGreaterThan(0)
        ->and($drawer['small'])->toBe([])
        ->and($drawer['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('approves a comment from its row, and Undo puts it back (D11, D26)', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/comments')
        ->click('[aria-label="Actions for the comment by Ada Guest"] >> text="Approve"')
        ->assertSee('Approved: comment by Ada Guest.')
        ->assertSee('Nothing is waiting')
        ->click('Undo')
        ->assertSee('Undone.')
        ->assertSee('Ada Guest');

    expect($this->held->refresh()->status)->toBe('pending');
});

it('marks a selection as spam from the bulk bar, then deletes the spam permanently after asking (D10, D12)', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/comments?view=approved')
        ->click('[aria-label="Select the comment by Bola Ade"]')
        ->assertSee('1 selected')
        ->click('[role="region"][aria-label="Bulk actions"] >> text="Mark as spam"')
        ->assertSee('Marked as spam: comment by Bola Ade.')
        ->navigate('/admin/comments?view=spam')
        ->click('[aria-label="Select all 2 comments shown"]')
        ->click('[role="region"][aria-label="Bulk actions"] >> text="Delete permanently"')
        ->assertSee('Delete 2 comments permanently?')
        ->assertSee('All 2 are deleted, with nothing kept. This can’t be undone.')
        ->click('[role="dialog"] >> text="Delete permanently"')
        ->assertSee('2 comments deleted permanently.')
        ->assertSee('No spam');

    expect($this->shown->fresh())->toBeNull()
        ->and($this->spam->fresh())->toBeNull();
});

it('opens a comment\'s details from its address, with the moderator\'s judgement, and Escape closes them (C7, D13, D16)', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/comments?comment='.$this->held->id)
        ->assertSee('Comment by Ada Guest')
        ->assertSee('Held: a first comment, or the first from this browser.')
        ->assertSee('Checked in this order. The first that applies decides.')
        ->assertSee('203.0.113.7')
        ->keys('[role="dialog"]', 'Escape')
        ->assertDontSee('Checked in this order. The first that applies decides.');
});

it('narrows the list to an item from the Comments column on Posts (D21)', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->click('[aria-label="2 approved comments"]')
        ->assertSee('On “Harbour news”')
        ->assertSee('Bola Ade')
        ->assertDontSee('Ada Guest');
});

it('says what deleting permanently will do when replies keep a place, for a selection and for a comment opened from its address (I-577, B-367, B-368)', function (): void {
    $dana = cmNext($this->item, 'spam', attributes: ['author_name' => 'Dana Spam']);
    $eli = cmNext($this->item, 'spam', attributes: ['author_name' => 'Eli Spam']);
    cmNext($this->item, 'approved', $dana);
    cmNext($this->item, 'approved', $eli);

    $page = cmSignIn($this->editor)
        ->navigate('/admin/comments?view=spam')
        ->click('[aria-label="Select the comment by Dana Spam"]')
        ->click('[aria-label="Select the comment by Eli Spam"]')
        ->click('[role="region"][aria-label="Bulk actions"] >> text="Delete permanently"')
        ->assertSee('Delete 2 comments permanently?')
        ->assertSee('All 2 have replies, so each stays in the thread')
        ->click('[role="dialog"] >> text="Cancel"');

    // Dana's comment isn't among the Pending view's rows: the drawer's own reply count says what will happen.
    $page->navigate('/admin/comments?view=pending&comment='.$dana->id)
        ->assertSee('Comment by Dana Spam')
        ->click('[data-slot="sheet-content"] >> text="Delete permanently"')
        ->assertSee('Delete the comment by Dana Spam permanently?')
        ->assertSee('It has 1 reply, so it stays in the thread');

    expect($dana->fresh())->not->toBeNull();
});

it('passes axe with a reply, Quick edit and Empty spam\'s confirmation open, every target 24 pixels or more, in the light and dark schemes (D19)', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)
        ->navigate('/admin/comments?view=all')
        ->click('[aria-label="Actions for the comment by Bola Ade"] >> text="Reply"')
        ->assertSee('Reply to Bola Ade')
        ->assertNotPresent('[role="menu"]')
        ->assertNoAccessibilityIssues(3);

    $reply = cmSmallTargets($page);

    $page->click('[aria-label="Actions for the comment by Ada Guest"] >> text="Quick edit"')
        ->assertSee('Quick edit: comment by Ada Guest')
        ->assertNotPresent('[role="menu"]')
        ->assertNoAccessibilityIssues(3);

    $quickEdit = cmSmallTargets($page);

    $page->navigate('/admin/comments?view=spam')
        ->click('Empty spam')
        ->assertSee('Delete 1 spam comment permanently?')
        // The dialog and its overlay fade in, and axe doesn't retry: it would read their colours mid-fade.
        ->assertScript("[...document.querySelectorAll('[data-slot=\"dialog-overlay\"], [role=\"dialog\"]')].map((element) => getComputedStyle(element).opacity).join(' ')", '1 1')
        ->assertNoAccessibilityIssues(3);

    $empty = cmSmallTargets($page);

    expect([$reply['small'], $quickEdit['small'], $empty['small']])->toBe([[], [], []])
        ->and(min($reply['checked'], $quickEdit['checked'], $empty['checked']))->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('edits a comment in Quick edit, opened with Q from its row, and keeps its words when only its name changes (C3, C11)', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/comments')
        ->keys('#cm-row-'.$this->held->id, 'q')
        ->assertSee('Quick edit: comment by Ada Guest')
        ->type('#qe-name', 'Ada Okafor')
        ->press('Save changes')
        ->assertSee('Saved the comment by Ada Okafor.');

    expect($this->held->refresh()->author_name)->toBe('Ada Okafor')
        ->and($this->held->body)->toEqual(cmBody('Lovely piece.'));
});

it('replies to a waiting comment from its row, which approves it, and records no address for the reply (C6)', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/comments')
        ->click('[aria-label="Actions for the comment by Ada Guest"] >> text="Reply"')
        ->assertSee('Replying also approves this comment.')
        ->type('#reply-'.$this->held->id, 'Thanks, the date is fixed now.')
        ->press('Reply and approve')
        ->assertSee('Reply posted. The comment by Ada Guest is approved too.')
        ->assertSee('Nothing is waiting');

    expect($this->held->refresh()->status)->toBe('approved')
        ->and(Comment::query()->where('parent_id', $this->held->id)->sole()->only(['author_name', 'status', 'ip', 'user_agent']))
        ->toBe(['author_name' => 'Eze Editor', 'status' => 'approved', 'ip' => null, 'user_agent' => null]);
});

it('moderates from the keyboard only while a row itself has focus (C11, I-653, D14)', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/comments?view=all')
        ->assertSee('Keyboard: J and K move between comments')
        ->keys('[name="search"]', 'a')
        ->keys('[aria-label="Select the comment by Ada Guest"]', 'a')
        ->keys('#cm-row-'.$this->ping->id, 'j')
        ->assertScript('document.activeElement?.id', 'cm-row-'.$this->shown->id)
        ->keys('#cm-row-'.$this->shown->id, 'x')
        ->assertSee('1 selected')
        ->keys('#cm-row-'.$this->held->id, 'a')
        ->assertSee('Approved: comment by Ada Guest.');

    expect($this->held->refresh()->status)->toBe('approved');
});

it('empties spam after a confirmation that says what stays (C4, D18)', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/comments?view=spam')
        ->click('Empty spam')
        ->assertSee('Delete 1 spam comment permanently?')
        ->assertSee('Every comment in Spam that was there when you opened this list is deleted. Spam that arrived since stays, for you to check first. This can’t be undone.')
        ->click('[role="dialog"] >> text="Empty spam"')
        ->assertSee('Emptied spam: deleted 1 comment permanently.')
        ->assertSee('Comments marked as spam wait here until you empty spam.');

    expect($this->spam->fresh())->toBeNull();
});
