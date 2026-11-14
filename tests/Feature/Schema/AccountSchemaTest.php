<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Support\Security\Operator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('refuses at the database to delete someone whose content would lose its author', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('author');

    app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'post',
        title: 'Kept',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        actorId: (string) $author->id,
    ));

    expect(fn () => DB::table('users')->where('id', $author->id)->delete())->toThrow(QueryException::class);
});

it('gives administrators the users.manage permission and no one else', function (): void {
    $this->seed(RoleSeeder::class);

    expect(User::factory()->create()->assignRole('administrator')->can('users.manage'))->toBeTrue()
        ->and(User::factory()->create()->assignRole('editor')->can('users.manage'))->toBeFalse();
});

it('has invited and suspended states', function (): void {
    expect(User::factory()->invited()->create()->invited_at)->not->toBeNull()
        ->and(User::factory()->suspended()->create()->suspended_at)->not->toBeNull();
});

it('knows the operator only when operator elevation is bound', function (): void {
    expect(Operator::isPresent())->toBeFalse();

    $this->withElevation();

    expect(Operator::isPresent())->toBeTrue();
});
