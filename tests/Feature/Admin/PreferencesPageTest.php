<?php

declare(strict_types=1);

use App\Domain\Preferences\Queries\PreferencesFor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('saves the person\'s own preferences through PATCH', function (): void {
    $this->actingAs($this->author)
        ->patch(route('preferences.update'), ['preferences' => ['editor.spellcheck' => false, 'lists.per_page' => 25]])
        ->assertRedirect();

    expect(PreferencesFor::user($this->author->refresh())['editor.spellcheck'])->toBeFalse()
        ->and(PreferencesFor::user($this->author)['lists.per_page'])->toBe(25);
});

it('refuses an undeclared key and writes nothing', function (): void {
    $this->actingAs($this->author)
        ->patch(route('preferences.update'), ['preferences' => ['nobody.declared' => true]])
        ->assertSessionHasErrors();

    expect($this->author->refresh()->preferences)->toBe([]);
});

it('answers a background save with 204 and no redirect', function (): void {
    $this->actingAs($this->author)
        ->patchJson(route('preferences.update'), ['preferences' => ['editor.rail_width' => 420]])
        ->assertNoContent();

    expect(PreferencesFor::user($this->author->refresh())['editor.rail_width'])->toBe(420);
});

it('takes the appearance from the preference, not the cookie, once someone is signed in', function (): void {
    $this->actingAs($this->author)
        ->patchJson(route('preferences.update'), ['preferences' => ['appearance.mode' => 'dark']])
        ->assertNoContent();

    $this->actingAs($this->author->refresh())
        ->withUnencryptedCookie('appearance', 'light')
        ->get(route('preferences.edit'))
        ->assertSee('<html lang="en" class="dark"', false);
});
