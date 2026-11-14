<?php

declare(strict_types=1);

use App\Domain\Seo\Queries\SearchEnginesNotice;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\WholeSite;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('warns administrators on Home while search engines are asked to stay away, linking to the switch (I-873)', function (): void {
    cmSettings([WholeSite::DISCOURAGE => true], SearchAndSocial::KEY);

    $this->actingAs($this->admin)->get('/admin')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('searchEngines', [
                'message' => 'Search engines are asked to stay away from this site.',
                'detail' => 'Every page asks search engines not to list it, and the sitemap is off.',
                'actionLabel' => 'Open Search and social',
                'actionUrl' => route('settings.seo').'#section-whole-site',
            ]));
});

it('says nothing on Home while search engines may list the site', function (): void {
    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn (Assert $page) => $page->component('dashboard')->where('searchEngines', null));
});

it('never warns someone who may not change Search and social, whatever the switch says (I-850)', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    cmSettings([WholeSite::DISCOURAGE => true], SearchAndSocial::KEY);

    $this->actingAs($editor)->get('/admin')
        ->assertInertia(fn (Assert $page) => $page->component('dashboard')->where('searchEngines', null));

    expect(app(SearchEnginesNotice::class)->forUser($editor))->toBeNull();
});
