<?php

declare(strict_types=1);

use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->create = fn (string $name, ?string $slug = null) => app(CreateTerm::class)->execute(new SaveTermInput('category', $name, $slug, null, null, (string) $this->editor->id));
});

it('asks for a name in plain words when a category has none', function (): void {
    expect(fn () => ($this->create)('  '))->toThrow(ValidationException::class, 'Give it a name.');
});

it('says what an address may hold when a category’s address holds anything else', function (): void {
    expect(fn () => ($this->create)('Harbour news', 'Harbour News!'))->toThrow(ValidationException::class, 'Use only lowercase letters, numbers and single hyphens in the address.');
});
