<?php

declare(strict_types=1);

use App\Domain\Preferences\PreferenceRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->hers = cmItem($this->author, 'Harbour news');
    $this->his = cmItem($this->editor, 'Market day');
    cmComment($this->hers);
    cmComment($this->hers);
    cmComment($this->hers, 'pending');
    cmComment($this->his, 'pending');
    $this->rows = fn (User $viewer, string $type = 'posts') => collect($this->actingAs($viewer)->get(route('content.index', ['type' => $type]))->viewData('page')['props']['rows'])->keyBy('title');
});

it('gives each row its approved count, and its waiting count to whoever looks after it (D21, I-204)', function (): void {
    $forAuthor = ($this->rows)($this->author);
    $forEditor = ($this->rows)($this->editor);

    expect($forAuthor['Harbour news']['comments'])->toBe(['approved' => 2, 'waiting' => 1, 'mine' => true])
        ->and($forAuthor['Market day']['comments'])->toBe(['approved' => 0, 'waiting' => null, 'mine' => false])
        ->and($forEditor['Market day']['comments'])->toBe(['approved' => 0, 'waiting' => 1, 'mine' => true])
        ->and($forEditor['Harbour news']['comments'])->toBe(['approved' => 2, 'waiting' => 1, 'mine' => true]);

    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page->where('takesComments', true));
});

it('has no Comments column while comments are switched off (D21, I-484)', function (): void {
    cmSettings(['comments' => 'off'], 'modules');

    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page->where('takesComments', false)->where('rows.0.comments', null));
});

it('offers the Comments column in Screen options, after the taxonomies and before Date, shown by default (D21)', function (): void {
    $registry = app(PreferenceRegistry::class);

    expect($registry->get('lists.post.columns')->default)->toBe(['author', 'taxonomy-category', 'taxonomy-tag', 'comments', 'date'])
        ->and($registry->get('lists.page.columns')->default)->toBe(['author', 'comments', 'date']);
});
