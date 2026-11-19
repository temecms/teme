<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('lists the tools an administrator may open, and leads the menu\'s Tools entry there (I-467)', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    $response = $this->actingAs($admin)->get('/admin/tools')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('tools/index')
        ->where('tools', [[
            'key' => 'jobs',
            'label' => 'Jobs',
            'icon' => 'refresh-cw',
            'description' => 'Background work: progress, failures and retries.',
            'href' => '/admin/tools/jobs',
            'external' => false,
        ], [
            'key' => 'updates',
            'label' => 'Updates',
            'icon' => 'download',
            'description' => 'The Teme this site runs, what is new, and updating from here.',
            'href' => '/admin/tools/updates',
            'external' => false,
        ], [
            'key' => 'ai-activity',
            'label' => 'AI activity',
            'icon' => 'wand-sparkles',
            'description' => 'Requests people made to AI, what they cost, and the month’s budget.',
            'href' => '/admin/tools/ai-activity',
            'external' => false,
        ], [
            'key' => 'import',
            'label' => 'Import',
            'icon' => 'upload',
            'description' => 'Move a WordPress site into Teme, and catch up with it later.',
            'href' => '/admin/tools/import',
            'external' => false,
        ], [
            'key' => 'backups',
            'label' => 'Backups',
            'icon' => 'download',
            'description' => 'Encrypted copies of the site, and whether they restore.',
            'href' => '/admin/tools/backups',
            'external' => false,
        ], [
            'key' => 'page-cache',
            'label' => 'Page cache',
            'icon' => 'layout-grid',
            'description' => 'Saved copies of public pages: clear, clear one address, or warm.',
            'href' => '/admin/tools/page-cache',
            'external' => false,
        ], [
            'key' => 'redirects',
            'label' => 'Redirects',
            'icon' => 'arrow-right',
            'description' => 'Send old addresses on to new ones; the 404 log.',
            'href' => '/admin/tools/redirects',
            'external' => false,
        ]]));

    expect(collect($response->viewData('page')['props']['nav'])->firstWhere('key', 'tools'))
        ->toMatchArray(['label' => 'Tools', 'icon' => 'wrench', 'href' => '/admin/tools', 'section' => 'site', 'badge' => 0]);
});

it('shows no Tools entry to someone who may open no tool, and refuses them the hub', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);

    $this->actingAs($person)->get('/admin/tools')->assertForbidden();

    expect(array_column($this->actingAs($person)->get('/admin')->viewData('page')['props']['nav'], 'key'))->not->toContain('tools');
})->with(['author', 'contributor']);

it('leads an editor to a hub of Redirects alone (I-850)', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->get('/admin/tools')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('tools', 1)->where('tools.0.key', 'redirects'));
});
