<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->editor = User::factory()->create()->assignRole('editor');

    $this->rows = fn (array $query = []): array => collect($this->actingAs($this->editor)
        ->get(route('content.index', ['type' => 'posts', ...$query]))
        ->assertOk()
        ->inertiaProps('rows'))->keyBy('title')->all();
});

it('gives a live post its address to view, and a draft, a private post and a post in the trash none (I-3130)', function (): void {
    $live = lifecycleDraft($this->editor, 'Harbour news');
    listsPublish($live, $this->editor);
    lifecycleDraft($this->editor, 'Ferry fares');
    $private = lifecycleDraft($this->editor, 'Staff notes');
    listsPublish($private, $this->editor);
    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($private->id, 'private', null, (string) $this->editor->id));
    $gone = lifecycleDraft($this->editor, 'Old notice');
    listsPublish($gone, $this->editor);
    app(TrashContent::class)->execute(new ContentActionInput($gone->id, (string) $this->editor->id));

    $rows = ($this->rows)();

    expect($rows['Harbour news']['viewUrl'])->toBe(Addresses::relativeUrl($live->refresh()))
        ->and($rows['Harbour news']['viewUrl'])->not->toBeNull()
        ->and($rows['Ferry fares']['viewUrl'])->toBeNull()
        ->and($rows['Staff notes']['viewUrl'])->toBeNull()
        ->and(($this->rows)(['tab' => 'trash'])['Old notice']['viewUrl'])->toBeNull();
});

it('reads the addresses in the same queries for one live post as for twenty, and for none', function (): void {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->rows)();

        return count(DB::getQueryLog());
    };

    lifecycleDraft($this->editor, 'A draft');
    $count(); // warms the permission cache
    $none = $count();

    foreach (range(1, 20) as $n) {
        listsPublish(lifecycleDraft($this->editor, "Post {$n}"), $this->editor);
    }

    expect($count())->toBe($none);
});
