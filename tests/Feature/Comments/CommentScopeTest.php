<?php

declare(strict_types=1);

use App\Domain\Comments\CommentModeration;
use App\Domain\Comments\Queries\CommentScope;
use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->items = [
        cmItem($this->author, 'Her post'),
        pgAuthorsPage($this->author, 'Her page', published: true),
        cmItem($this->editor, 'His post'),
        cmItem($this->editor, 'His page', 'page'),
    ];
});

it('looks after exactly the items CommentModeration allows, for every role (D2, I-483, I-570)', function (string $who, int $count): void {
    $person = $who === 'the author' ? $this->author : User::factory()->create()->assignRole($who);

    $query = DB::table('contents')->whereIn('contents.id', array_map(fn (Content $item): string => $item->id, $this->items));
    CommentScope::forViewer($person)->constrain($query);
    $scoped = $query->pluck('contents.id')->sort()->values()->all();

    $allowed = collect($this->items)->filter(fn (Content $item): bool => CommentModeration::allows($person, $item))->pluck('id')->sort()->values()->all();

    expect($scoped)->toHaveCount($count)->toBe($allowed);
})->with([
    'an administrator' => ['administrator', 4],
    'an editor' => ['editor', 4],
    // Her post alone: a page she wrote is an editor's to look after (I-3313).
    'the items\' author' => ['the author', 1],
    'another author' => ['author', 0],
    'a contributor' => ['contributor', 0],
]);

it('lets whoever holds comments.moderate look after every item, publishing none, and no one else see addresses (I-570, I-270 B, D14)', function (): void {
    $moderator = CommentScope::forViewer(User::factory()->create()->givePermissionTo('comments.moderate'));
    $author = CommentScope::forViewer($this->author);

    expect([$moderator->everyItem, $moderator->any()])->toBe([true, true])
        ->and([$author->everyItem, $author->any()])->toBe([false, true]);
});

it('opens the Comments screen to whoever looks after any item\'s comments, and to no one else (D18)', function (): void {
    expect($this->author->can(CommentModeration::LOOK_AFTER))->toBeTrue()
        ->and($this->editor->can(CommentModeration::LOOK_AFTER))->toBeTrue()
        ->and(User::factory()->create()->givePermissionTo('comments.moderate')->can(CommentModeration::LOOK_AFTER))->toBeTrue()
        ->and(User::factory()->create()->assignRole('contributor')->can(CommentModeration::LOOK_AFTER))->toBeFalse()
        ->and(User::factory()->create()->can(CommentModeration::LOOK_AFTER))->toBeFalse();
});
