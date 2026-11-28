<?php

declare(strict_types=1);

use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Content\ContentReader;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('librarian');

    $this->reader = fn (array $capabilities = ['content.read']): ContentReader => app(PluginDoors::class)
        ->for('tests/librarian', 'librarian', $capabilities, [])[ContentReader::class];
});

afterEach(function (): void {
    PluginFixtures::forget();
    app(CurrentJobActor::class)->clear();
});

/**
 * Librarian enabled with people.read as agreed from 1.9 on, stamped for the
 * owner's name; without an enabled record no name is given.
 */
function contentOwnerGranted(): void
{
    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/librarian',
        'code' => 'librarian',
        'consent' => ['capabilities' => ['content.read', 'people.read'], 'hosts' => [], 'hooks' => [], 'routes' => [], 'peopleRead' => true],
    ]);
    app(PluginRecords::class)->changed();
}

it('gives the item’s owner id under content.read, and never the name without people.read', function (): void {
    $owner = User::factory()->create(['name' => 'Ada Harbour'])->assignRole('editor');
    $item = addrPublished($owner, 'Harbour notes');
    app(CurrentJobActor::class)->set(JobActor::person($owner->id));

    $draft = ($this->reader)(['content.read'])->draft($item->id, (string) $owner->id);

    expect($draft->ownerId)->toBe((string) $owner->id)
        ->and($draft->ownerName)->toBeNull();
});

it('gives the owner’s display name only when the plugin also has people.read', function (): void {
    $owner = User::factory()->create(['name' => 'Ada Harbour'])->assignRole('editor');
    $item = addrPublished($owner, 'Harbour notes');
    app(CurrentJobActor::class)->set(JobActor::person($owner->id));
    contentOwnerGranted();

    $draft = ($this->reader)(['content.read', 'people.read'])->draft($item->id, (string) $owner->id);

    expect($draft->ownerId)->toBe((string) $owner->id)
        ->and($draft->ownerName)->toBe('Ada Harbour');
});

it('never puts a role or email on the snapshot’s owner fields', function (): void {
    $owner = User::factory()->create(['name' => 'Ada Harbour', 'email' => 'ada@harbour.example'])->assignRole('administrator');
    $item = addrPublished($owner, 'Harbour notes');
    app(CurrentJobActor::class)->set(JobActor::person($owner->id));
    contentOwnerGranted();

    $draft = ($this->reader)(['content.read', 'people.read'])->draft($item->id, (string) $owner->id);

    expect($draft->ownerId)->toBe((string) $owner->id)
        ->and($draft->ownerName)->toBe('Ada Harbour')
        ->and(json_encode($draft))->not->toContain('ada@harbour.example')
        ->and(json_encode($draft))->not->toContain('administrator');
});
