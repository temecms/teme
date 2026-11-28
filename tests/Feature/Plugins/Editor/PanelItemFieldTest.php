<?php

declare(strict_types=1);

use App\Domain\Plugins\Area\AreaForms;
use App\Domain\Plugins\Editor\PanelValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// A panel action's items field takes one post or page, by its content ID, and the
// person running the action must be able to open it.

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('editor');
    $this->stranger = User::factory()->create();
});

it('takes one content ID for an items field, and nothing else', function (): void {
    $validator = app(PanelValidator::class);

    expect($validator->fits(['type' => 'items'], '01HZ0000000000000000000000'))->toBeTrue()
        ->and($validator->fits(['type' => 'items'], ''))->toBeFalse()
        ->and($validator->fits(['type' => 'items'], ['01HZ0000000000000000000000']))->toBeFalse()
        ->and($validator->fits(['type' => 'items'], 42))->toBeFalse();
});

it('reads back only the items the person may open, so an action refuses a draft someone else holds', function (): void {
    $draft = lifecycleDraft($this->author, 'Roses not yet');

    expect(AreaForms::readableItems([(string) $draft->id], (string) $this->author->id))->toBe([(string) $draft->id])
        ->and(AreaForms::readableItems([(string) $draft->id], (string) $this->stranger->id))->toBe([]);
});
