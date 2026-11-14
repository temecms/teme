<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\Setting;

uses(RefreshDatabase::class);

it('lets a number setting name the unit it counts in, and no other kind (I-3128)', function (): void {
    expect(Setting::number('keep_days', 'Keep for', 'Days kept.', '0.1.0')->unit('days')->unit)->toBe('days')
        ->and(Setting::number('per_page', 'Per page', 'How many.', '0.1.0')->unit)->toBeNull()
        ->and(fn () => Setting::text('title', 'Title', 'The name.', '0.1.0')->unit('days'))
        ->toThrow(InvalidArgumentException::class, 'Only number settings may count in a unit; [title] is text.');
});

it('says the unit beside each of Discussion\'s day and level counts, and none for a plain number', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');

    $units = collect($this->actingAs($admin)->get(route('settings.discussion'))->assertOk()->inertiaProps('fieldsets'))
        ->flatMap(fn (array $fieldset): array => $fieldset['fields'])
        ->pluck('unit', 'key')
        ->all();

    expect($units)->toMatchArray([
        'close_after_days' => 'days',
        'thread_depth' => 'levels',
        'keep_ip_days' => 'days',
        'per_page' => null,
        'hold_links' => null,
    ]);
});
