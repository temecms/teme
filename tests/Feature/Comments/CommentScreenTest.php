<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->hers = cmItem($this->author, 'Harbour news');
    $this->his = cmItem($this->editor, 'Market day');
});

it('opens the Pending view for whoever looks after comments, with its views, its rows and who they are (D4, D14)', function (): void {
    $waiting = cmNext($this->hers, 'pending');
    cmNext($this->his, 'pending');

    cmScreen($this->author)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('comments/index')
        ->where('view', 'pending')
        ->where('own', true)
        ->where('seesAddresses', false)
        ->has('rows', 1)
        ->where('rows.0.id', $waiting->id)
        ->where('views.1', ['key' => 'pending', 'label' => 'Pending', 'count' => 1])
        ->where('item', null)
        ->where('details', null)
        ->where('paging.newer', null)
        ->where('paging.older', null));

    cmScreen($this->editor)->assertInertia(fn (Assert $page) => $page
        ->where('own', false)
        ->where('seesAddresses', true)
        ->has('rows', 2));
});

it('refuses the screen to someone who looks after no comments (D18)', function (): void {
    cmScreen(User::factory()->create()->assignRole('contributor'))->assertForbidden();
});

it('answers as if the screen weren\'t there while comments are switched off, and refuses its changes (D15)', function (): void {
    $comment = cmNext($this->hers, 'pending');
    cmSettings(['comments' => 'off'], 'modules');

    cmScreen($this->editor)->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('comments/off'));
    $this->actingAs($this->editor)->post(route('comments.moderate'), ['moves' => [['id' => $comment->id, 'to' => 'approved']]])->assertNotFound();
    $this->actingAs($this->editor)->post(route('comments.delete'), ['ids' => [$comment->id]])->assertNotFound();

    expect($comment->refresh()->status)->toBe('pending');
});

it('refuses a list narrowed to an item someone else looks after, in v9\'s words, and 404 for an item that isn\'t there (C1, D15)', function (): void {
    cmScreen($this->author, ['view' => 'spam', 'item' => $this->his->id])
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('comments/refused')
            ->where('message', 'You don’t look after the comments on “Market day”. Editors and administrators look after every item’s comments, and authors those on their own posts.')
            ->where('back', ['label' => 'Show the comments you look after', 'href' => route('comments.index', ['view' => 'spam'], false)]));

    cmScreen($this->editor, ['item' => cmId()])->assertNotFound();
});

it('refuses an author the comments of a page she wrote before pages were editors’ (I-3313)', function (): void {
    $about = pgAuthorsPage($this->author, 'About us', published: true);

    cmScreen($this->author, ['item' => $about->id])
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('comments/refused')
            ->where('message', 'You don’t look after the comments on “About us”. Editors and administrators look after every item’s comments, and authors those on their own posts.')
            ->where('back', ['label' => 'Show the comments you look after', 'href' => route('comments.index', [], false)]));

    cmScreen($this->editor, ['item' => $about->id])->assertOk();
});

it('narrows the list and its counts to an item, naming it for the chip (D5, D13)', function (): void {
    cmNext($this->his, 'pending');
    cmNext($this->hers, 'pending');

    cmScreen($this->editor, ['view' => 'all', 'item' => $this->his->id])->assertInertia(fn (Assert $page) => $page
        ->where('item', ['id' => $this->his->id, 'title' => 'Market day'])
        ->where('filters', ['item' => $this->his->id])
        ->where('views.0.count', 1)
        ->has('rows', 1));
});

it('opens the drawer from its address, in the comment\'s own view when none is named (C7, D13)', function (): void {
    $spam = cmNext($this->hers, 'spam');

    cmScreen($this->author, ['comment' => $spam->id])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('view', 'spam')
        ->where('details.id', $spam->id)
        ->where('details.statusLabel', 'Spam')
        ->where('rows.0.id', $spam->id));
});

it('reloads the drawer alone, without the list (D13)', function (): void {
    $comment = cmNext($this->hers, 'pending');

    cmScreen($this->author, ['comment' => $comment->id])->assertInertia(fn (Assert $page) => $page
        ->reloadOnly('details', fn (Assert $reload) => $reload
            ->where('details.id', $comment->id)
            ->missing('rows')
            ->missing('views')));
});

it('refuses a drawer for a comment on someone else\'s item, and 404 for one that isn\'t there or is a tombstone (C7, D13, D15)', function (): void {
    $theirs = cmNext($this->his, 'pending');
    $tombstone = cmNext($this->his, 'removed', attributes: ['author_name' => null, 'author_email' => null, 'body' => null]);

    cmScreen($this->author, ['comment' => $theirs->id])->assertForbidden()->assertInertia(fn (Assert $page) => $page->component('comments/refused'));
    cmScreen($this->editor, ['comment' => cmId()])->assertNotFound();
    cmScreen($this->editor, ['comment' => $tombstone->id])->assertNotFound();
    cmScreen($this->editor, ['comment' => 'not-a-comment'])->assertNotFound();
});

it('says "Move to trash" in the row menu, as every screen does, and never a bare "Trash" (issue 89)', function (): void {
    $source = uiComponent('pages/comments/index.tsx');

    expect($source)->not->toContain("__('Trash')")
        ->and($source)->toContain("label: __('Move to trash')")
        ->and($source)->toContain('entry(row, trash)');
});
