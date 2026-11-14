<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Queries\LinkedContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('says whether each linked article is published, unpublished, in the trash or gone', function (): void {
    $live = lifecycleDraft($this->author, 'Harbour festival');
    listsPublish($live, $this->author);
    $draft = lifecycleDraft($this->author, 'Night market');
    $trashed = lifecycleDraft($this->author, 'Lantern parade');
    app(TrashContent::class)->execute(new ContentActionInput($trashed->id, (string) $this->author->id));
    $gone = '01k5z9p2qh7n3x4c8v6b0m1t2r';

    expect(LinkedContent::states([$live->id, $draft->id, $trashed->id, $gone]))->toBe([
        $live->id => ['title' => 'Harbour festival', 'state' => 'published'],
        $draft->id => ['title' => 'Night market', 'state' => 'unpublished'],
        $trashed->id => ['title' => 'Lantern parade', 'state' => 'trashed'],
        $gone => ['title' => null, 'state' => 'missing'],
    ]);
});

it('answers a contributor for links added while writing', function (): void {
    $draft = lifecycleDraft($this->author, 'Night market');
    $contributor = User::factory()->create()->assignRole('contributor');

    $this->actingAs($contributor)
        ->getJson('/admin/content/linked?'.http_build_query(['ids' => [$draft->id]]))
        ->assertOk()
        ->assertJsonPath("{$draft->id}.state", 'unpublished')
        ->assertJsonPath("{$draft->id}.title", 'Night market');
});

it('refuses the link states to someone who may not write', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/admin/content/linked?'.http_build_query(['ids' => ['01k5z9p2qh7n3x4c8v6b0m1t2r']]))
        ->assertForbidden();
});

it('refuses more than 100 IDs at once', function (): void {
    $ids = array_map(fn (int $n): string => str_pad((string) $n, 26, '0', STR_PAD_LEFT), range(1, 101));

    $this->actingAs($this->author)
        ->getJson('/admin/content/linked?'.http_build_query(['ids' => $ids]))
        ->assertUnprocessable();
});

it('opens the editor with the states of the articles the document links to', function (): void {
    $draft = lifecycleDraft($this->author, 'Night market');
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(schedLinkTo($draft->id, 'the market')));

    $this->actingAs($this->author)->get("/admin/content/{$content->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where("session.linked.{$draft->id}.state", 'unpublished')
            ->where("session.linked.{$draft->id}.title", 'Night market'));
});
