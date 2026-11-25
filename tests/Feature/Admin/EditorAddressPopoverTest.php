<?php

/**
 * The editor's Address popover: tidying on leave, emptying to the title,
 * the popover's own words, and the address travelling only with a real save.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
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
    ]))->toBe([
        'when-the-new-fares-start',
        'temecms-1-0',
        'café-menu',
        '日本語タイトル',
        'suvs-with-the-highest-death-rates',
        'harbour-festival',
        'hello-world',
        'user-at-harbour',
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
        ->toContain('sidePending: slug !== savedSlug')
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
