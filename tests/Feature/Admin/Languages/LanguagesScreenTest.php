<?php

declare(strict_types=1);

use App\Domain\Languages\SiteLanguages;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

/**
 * The languages group's version now.
 */
function lsVersion(): int
{
    return (int) DB::table('settings')->where('group_key', SiteLanguages::GROUP)->value('version');
}

it('gives Settings › Languages every language in order, the default first, with its prefix, whether it is shown and what is in it', function (): void {
    $this->actingAs($this->site->admin)->get('/admin/settings/languages')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/languages')
            ->where('languages.0.tag', 'en-GB')
            ->where('languages.0.isDefault', true)
            ->where('languages.0.prefix', '')
            ->where('languages.1.tag', 'fr')
            ->where('languages.1.prefix', 'fr')
            ->where('languages.1.shown', true)
            ->where('languages.1.items', 2)
            ->where('languages.1.terms', 1)
            ->where('languages.2.tag', 'es')
            ->where('languages.2.shown', false)
            ->where('languages.2.isDefault', false)
            ->where('languages.2.items', 1)
            ->where('languages.2.terms', 0)
            ->where('version', lsVersion())
            ->has('choices')
            ->has('types')
            ->has('taxonomies'));
});

it('offers to add only the languages the site doesn\'t have yet', function (): void {
    $this->actingAs($this->site->admin)->get('/admin/settings/languages')
        ->assertInertia(fn (Assert $page) => $page->where('choices', fn ($choices): bool => collect($choices)->pluck('tag')->intersect(['en-GB', 'fr', 'es'])->isEmpty()
            && collect($choices)->pluck('tag')->contains('de')));
});

it('saves a new order from the row menu, and answers with a toast', function (): void {
    $this->actingAs($this->site->admin)
        ->put('/admin/settings/languages/order', ['tags' => ['es', 'fr'], 'expected_version' => lsVersion()])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Saved the order of the languages.');

    expect(app(SiteLanguages::class)->others())->toBe(['es', 'fr']);
});

it('refuses an order opened on an older version with words, and writes nothing', function (): void {
    $before = DB::table('settings')->where('group_key', SiteLanguages::GROUP)->first();

    $this->actingAs($this->site->admin)
        ->put('/admin/settings/languages/order', ['tags' => ['es', 'fr'], 'expected_version' => lsVersion() - 1])
        ->assertSessionHasErrors(['expected_version' => 'Someone changed the languages while you were editing. Reload and try again.']);

    expect(DB::table('settings')->where('group_key', SiteLanguages::GROUP)->first())->toEqual($before);
});

it('refuses an editor the order, and writes nothing', function (): void {
    $before = DB::table('settings')->where('group_key', SiteLanguages::GROUP)->first();

    $this->actingAs($this->site->editor)
        ->put('/admin/settings/languages/order', ['tags' => ['es', 'fr'], 'expected_version' => lsVersion()])
        ->assertForbidden();

    expect(DB::table('settings')->where('group_key', SiteLanguages::GROUP)->first())->toEqual($before);
});
