<?php

declare(strict_types=1);

use App\Domain\Users\Actors;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps nobody into the next job or request, whose people are read afresh (I-087)', function (): void {
    $this->seed(RoleSeeder::class);
    $person = User::factory()->create()->assignRole('editor');
    app(Actors::class)->keep((string) $person->id);

    // As another request might, while a pass or a batch keeps them.
    $person->syncRoles(['contributor']);

    expect(app(Actors::class)->find((string) $person->id)?->hasRole('editor'))->toBeTrue();

    // What the queue worker does before each job, and Octane before each request.
    app()->forgetScopedInstances();

    expect(app(Actors::class)->find((string) $person->id)?->hasRole('contributor'))->toBeTrue();
});
