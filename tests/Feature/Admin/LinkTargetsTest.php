<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('offers the articles a link can point at, by title, with their IDs as stored', function (): void {
    $market = lifecycleDraft($this->author, 'Night market');
    lifecycleDraft($this->author, 'Harbour festival');
    $gone = lifecycleDraft($this->author, 'Night bus');
    app(TrashContent::class)->execute(new ContentActionInput($gone->id, (string) $this->author->id));

    $this->actingAs($this->author)
        ->getJson('/admin/content/link-targets?q=night')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $market->id)
        ->assertJsonPath('0.title', 'Night market')
        ->assertJsonPath('0.status', 'draft');

    expect($market->id)->toBe(strtolower($market->id));
});

it('refuses the link search to someone who may not write', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/admin/content/link-targets?q=night')
        ->assertForbidden();
});
