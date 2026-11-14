<?php

declare(strict_types=1);

use App\Domain\Seo\WholeSite;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->save = fn (array $values, int $version = 0) => $this->actingAs($this->admin)->from('/admin/settings/seo')
        ->put('/admin/settings/seo', ['values' => $values, 'expected_version' => $version]);
});

it('draws Settings › Search and social at its own address, for administrators alone (I-850, I-874)', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($this->admin)->get('/admin/settings/seo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/search-and-social')
            ->where('version', 0)
            ->where('live', false)
            ->has('sections')
            ->has('titles.rows'));

    $this->actingAs($editor)->get('/admin/settings/seo')->assertForbidden();
    $this->actingAs($editor)->put('/admin/settings/seo', ['values' => ['feed_items' => 5], 'expected_version' => 0])->assertForbidden();

    expect(DB::table('settings')->where('group_key', 'seo')->exists())->toBeFalse();
});

it('saves through UpdateSettings, and says what changed when search engines are asked away or welcomed back (I-873)', function (): void {
    ($this->save)([WholeSite::DISCOURAGE => '1'])
        ->assertRedirect('/admin/settings/seo')
        ->assertInertiaFlash('toast.message', 'Search and social settings saved. Search engines are asked to stay away from every page, and the sitemap is off.');

    ($this->save)(['feed_items' => '25'], 1)
        ->assertInertiaFlash('toast.message', 'Search and social settings saved.');

    ($this->save)([WholeSite::DISCOURAGE => '0'], 2)
        ->assertInertiaFlash('toast.message', 'Search and social settings saved. Search engines may list the site again, and the sitemap is back.');

    expect(WholeSite::discouraged())->toBeFalse();
});

it('refuses a form opened on an older version, and a value out of range, writing nothing', function (): void {
    ($this->save)(['feed_items' => '0'])->assertSessionHasErrors('values.feed_items');
    ($this->save)(['feed_items' => '25'], 3)->assertSessionHasErrors(['expected_version' => 'These settings were changed by someone else while you had them open. Reload the page and try again.']);

    expect(DB::table('settings')->where('group_key', 'seo')->exists())->toBeFalse();
});
