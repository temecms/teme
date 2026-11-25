<?php

/**
 * The editor's Address popover: tidying on leave, emptying to the title,
 * the popover's own words, and the address travelling only with a real save.
 *
 * @package Teme
 * @license MIT
 */

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

const ADDR_RELEASE = '01K9F1E1D0000000000000RD01';
const ADDR_RATING = '01K9F1E1D0000000000000RA03';

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
});

it('tidies a typed address keeping letters of every script', function (): void {
    expect(guardCall('components/editor/tidy-slug.ts', 'tidySlug', [
        ['When the new fares start'],
        ['-temecms--1-0'],
        ['Café Menu'],
        ['日本語タイトル'],
        ['suvs-with-the-highest-death-rates/'],
        ['https://example.org/harbour-festival/'],
        ['  Hello   World  '],
        ['user@harbour'],
        ['नमस्ते दुनिया'],
        ['สวัสดีชาวโลก'],
    ]))->toBe([
        'when-the-new-fares-start',
        'temecms-1-0',
        'café-menu',
        '日本語タイトル',
        'suvs-with-the-highest-death-rates',
        'harbour-festival',
        'hello-world',
        'user-at-harbour',
        'नमस्ते-दुनिया',
        'สวัสดีชาวโลก',
    ]);
});

it('gives an emptied address the slug from the title, or untitled', function (): void {
    expect(guardCall('components/editor/tidy-slug.ts', 'slugFromTitle', [
        ['Harbour festival'],
        ['!!!'],
        [''],
    ]))->toBe([
        'harbour-festival',
        'untitled',
        'untitled',
    ]);
});

it('draws the address popover headed with close, copy, and no Change address button', function (): void {
    $panel = uiComponent('components/editor/rail/publish-panel.tsx');

    expect($panel)
        ->toContain('data-test="address-popover"')
        ->toContain('data-test="address-close"')
        ->toContain('data-test="address-copy"')
        ->toContain('Copy the address')
        ->toContain('Address copied')
        ->toContain("The last part of this post's address.")
        ->toContain('The old address will keep sending readers here once you update.')
        ->toContain('tidySlug')
        ->toContain('slugFromTitle')
        ->not->toContain('Change address');
});

it('saves a pending address with Save draft or Update, never from a lone address post', function (): void {
    $edit = uiComponent('pages/content/edit.tsx');

    expect($edit)
        ->toContain("slug === '' || slug !== savedSlug")
        ->toContain('errors?.slug')
        ->toContain('Another post already uses this address. Add a word to make it its own.')
        ->not->toContain('saveAddressIfNeeded')
        ->not->toContain('ContentEditorController.address');
});

it('saves a draft address with the draft save, and refuses a taken one with the slug key', function (): void {
    $draft = lifecycleDraft($this->author, 'Harbour festival');
    lifecycleDraft($this->author, 'Taken')->forceFill(['slug' => 'taken'])->save();

    $this->actingAs($this->author)->postJson("/admin/content/{$draft->id}/checkpoint", [
        'slug' => 'harbour-2026',
    ])->assertOk()->assertJsonPath('slug', 'harbour-2026');

    expect($draft->refresh()->slug)->toBe('harbour-2026');

    $this->actingAs($this->author)->postJson("/admin/content/{$draft->id}/checkpoint", [
        'slug' => 'taken',
    ])->assertUnprocessable()->assertJsonValidationErrors('slug');

    expect($draft->refresh()->slug)->toBe('harbour-2026');
});

it('leaves a live post\'s address alone on autosave and checkpoint', function (): void {
    $post = addrPublished($this->author, 'Hello World');
    $original = $post->slug;

    edSave($post->id, $this->author, docWith(docText('A typed change.')), title: 'Hello World');
    expect($post->refresh()->slug)->toBe($original);

    $this->actingAs($this->author)->postJson("/admin/content/{$post->id}/checkpoint")
        ->assertOk();
    expect($post->refresh()->slug)->toBe($original);

    // A slug on checkpoint must not move a live address either.
    $this->actingAs($this->author)->postJson("/admin/content/{$post->id}/checkpoint", [
        'slug' => 'should-not-apply',
    ])->assertOk();
    expect($post->refresh()->slug)->toBe($original);
});

it('changes a live post\'s address on Update and redirects the old one', function (): void {
    $post = addrPublished($this->author, 'Hello World');

    $this->actingAs($this->author)->postJson("/admin/content/{$post->id}/publish", [
        'revision_id' => $post->working_revision_id,
        'slug' => 'hello-again',
    ])->assertOk();

    expect($post->refresh()->slug)->toBe('hello-again');
    expect(addrLocation(addrGet('/hello-world/')->assertStatus(301)))->toBe('/hello-again/');
});

it('keeps the autosave and writes no revision when a taken address arrives with the checkpoint', function (): void {
    $draft = lifecycleDraft($this->author, 'Harbour festival');
    lifecycleDraft($this->author, 'Taken')->forceFill(['slug' => 'taken'])->save();
    edSave($draft->id, $this->author, docWith(docText('Typed while drafting.')));

    $revisions = DB::table('content_revisions')->where('content_id', $draft->id)->count();
    $working = $draft->working_revision_id;
    $slug = $draft->slug;

    $this->actingAs($this->author)->postJson("/admin/content/{$draft->id}/checkpoint", [
        'slug' => 'taken',
    ])->assertUnprocessable()->assertJsonValidationErrors('slug');

    expect(DB::table('content_revisions')->where('content_id', $draft->id)->count())->toBe($revisions)
        ->and($draft->refresh()->working_revision_id)->toBe($working)
        ->and($draft->slug)->toBe($slug)
        ->and(DB::table('content_autosaves')->where('content_id', $draft->id)->where('user_id', $this->author->id)->count())->toBe(1);
});

it('leaves a live address alone when publish with a pending address is refused', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(ADDR_RELEASE, 'release_date', ['fieldType' => 'datetime', 'label' => 'Release date', 'settings' => ['dateOnly' => true], 'required' => true]), 1);
    StoredFieldRows::store(StoredFieldRows::entry(ADDR_RATING, 'rating', ['fieldType' => 'select', 'settings' => ['choices' => [['value' => 'g', 'label' => 'G']]]]), 2);

    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie');
    docSave($movie, $this->editor, docWith(docText('A film.')), [ADDR_RELEASE => '2016-11-11']);
    listsPublish($movie, $this->editor);
    $original = $movie->refresh()->slug;

    StoredFieldRows::change(ADDR_RATING, ['required' => true]);

    $this->actingAs($this->editor)->postJson("/admin/content/{$movie->id}/publish", [
        'revision_id' => $movie->working_revision_id,
        'slug' => 'arrival-again',
    ])->assertUnprocessable();

    expect($movie->refresh()->slug)->toBe($original)
        ->and($movie->status)->toBe('published');
});

it('does not move a live post\'s address from preview or a share link', function (): void {
    $post = addrPublished($this->author, 'Hello World');
    $original = $post->slug;

    $this->actingAs($this->author)->get(route('content.preview', ['content' => $post->id]))->assertOk();
    expect($post->refresh()->slug)->toBe($original);

    $this->actingAs($this->author)->postJson("/admin/content/{$post->id}/preview-links")->assertCreated();
    expect($post->refresh()->slug)->toBe($original);
});

it('keeps an emptied address pending against the title\'s address', function (): void {
    expect(uiComponent('pages/content/edit.tsx'))
        ->toContain("slug === '' || slug !== savedSlug")
        ->and(uiComponent('components/editor/rail/publish-panel.tsx'))
        ->toContain('slugFromTitle')
        ->toContain('// Empty stays empty so the server makes the title\'s address on save.');
});
