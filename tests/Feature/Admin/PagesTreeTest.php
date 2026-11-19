<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('draws the pages under their parents, in their order, leaving out the trash', function (): void {
    $tree = PageTree::build();

    $nodes = $this->actingAs($tree->admin)->get(route('content.tree', ['type' => 'pages']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('content/tree')->where('cut', false))
        ->inertiaProps('nodes');
    $about = collect($nodes)->firstWhere('id', $tree->about->id);
    $titles = fn (array $list): array => array_column($list, 'title');

    expect($about['children'][0]['title'])->toBe($tree->team->title)
        ->and($about['children'][0]['children'][0]['title'])->toBe($tree->people->title)
        ->and($about['address'])->not->toBeNull()
        ->and($titles($about['children']))->not->toContain($tree->archived->title)
        ->and($titles($nodes))->toContain($tree->services->title);
});

it('has no tree for a type that doesn’t nest', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($admin)->get(route('content.tree', ['type' => 'posts']))->assertNotFound();
});

it('keeps the tree from someone who may not open the list', function (): void {
    $nobody = User::factory()->create();

    $this->actingAs($nobody)->get(route('content.tree', ['type' => 'pages']))->assertForbidden();
});
