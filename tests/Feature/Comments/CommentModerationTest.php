<?php

declare(strict_types=1);

use App\Domain\Comments\CommentModeration;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->item = cmItem($this->author);
});

it('lets editors and administrators look after every item\'s comments', function (): void {
    expect(CommentModeration::allows(User::factory()->create()->assignRole('editor'), $this->item))->toBeTrue()
        ->and(CommentModeration::allows(User::factory()->create()->assignRole('administrator'), $this->item))->toBeTrue();
});

it('lets an author look after the comments on their own items, and on nobody else\'s (I-483, I-570)', function (): void {
    $other = User::factory()->create()->assignRole('author');

    expect(CommentModeration::allows($this->author, $this->item))->toBeTrue()
        ->and(CommentModeration::allows($other, $this->item))->toBeFalse();
});

it('never lets a contributor look after comments, even on their own published item (I-483, option A)', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $theirs = lifecycleDraft($contributor, 'Low tide');
    listsPublish($theirs, User::factory()->create()->assignRole('editor'));

    expect(CommentModeration::allows($contributor, $theirs->refresh()))->toBeFalse();
});

it('lets whoever holds comments.moderate look after every item\'s comments without publishing any', function (): void {
    $moderator = User::factory()->create()->givePermissionTo('comments.moderate');

    expect(CommentModeration::allows($moderator, $this->item))->toBeTrue();
});
