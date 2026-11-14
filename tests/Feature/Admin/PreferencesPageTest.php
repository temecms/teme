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

it('keeps the look and high contrast when the rest of the page is saved without them', function (): void {
    lscrPrefer($this->author, ['appearance.look' => 'quiet', 'appearance.high_contrast' => true]);

    $this->actingAs($this->author)
        ->patch(route('preferences.update'), ['preferences' => ['editor.spellcheck' => false]])
        ->assertRedirect();

    expect(PreferencesFor::user($this->author->refresh()))->toMatchArray([
        'appearance.look' => 'quiet',
        'appearance.high_contrast' => true,
        'editor.spellcheck' => false,
    ]);
});

it('draws the look and high contrast outside the form, each applied and saved as it is chosen', function (): void {
    expect(uiComponent('pages/account/preferences.tsx'))
        ->toContain('chooseLook(')
        ->toContain('Saved as you choose.')
        ->toContain('id="appearance-high-contrast"')
        ->toContain('Your choice couldn’t be saved, so the admin is back as it was. Try again in a moment.')
        ->not->toContain('name={`preferences[${LOOK}]`}')
        ->not->toContain('name={`preferences[${CONTRAST}]`}');
});

it('puts a chosen look in the shared preferences at once, and puts the earlier one back only while the refused one is still shown', function (): void {
    expect(uiComponent('lib/preferences.ts'))
        ->toContain('export async function chooseLook')
        ->toMatch('/router\.replaceProp\(\s*\'preferences\'/')
        ->toContain('current[key] === value')
        ->toContain("'put back'");
});
