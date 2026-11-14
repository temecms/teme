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
            'icon' => 'list-checks',
            'description' => 'Imports and other long tasks: progress, failures and retries.',
            'href' => '/admin/tools/jobs',
            'external' => false,
        ], [
            'key' => 'import',
            'label' => 'Import',
            'icon' => 'import',
            'description' => 'Move a WordPress site into Teme, and see what came across.',
            'href' => '/admin/tools/import',
            'external' => false,
        ], [
            'key' => 'backups',
            'label' => 'Backups',
            'icon' => 'archive',
            'description' => 'Encrypted copies of the site, where they’re kept, and whether they can be read back.',
            'href' => '/admin/tools/backups',
            'external' => false,
        ], [
            'key' => 'updates',
            'label' => 'Updates',
            'icon' => 'refresh-cw',
            'description' => 'Which Teme this site runs, the newest release, and what changed.',
            'href' => '/admin/tools/updates',
            'external' => false,
        ], [
            'key' => 'page-cache',
            'label' => 'Page cache',
            'icon' => 'layers',
            'description' => 'Saved copies of public pages, and clearing them when one looks out of date.',
            'href' => '/admin/tools/page-cache',
            'external' => false,
        ], [
            'key' => 'redirects',
            'label' => 'Redirects',
            'icon' => 'link',
            'description' => 'Send old addresses on to new ones, and see which addresses readers couldn’t find.',
            'href' => '/admin/tools/redirects',
            'external' => false,
        ], [
            'key' => 'ai-activity',
            'label' => 'AI activity',
            'icon' => 'sparkles',
            'description' => 'Every request people made to AI, what it cost, and what became of each.',
            'href' => '/admin/tools/ai-activity',
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
