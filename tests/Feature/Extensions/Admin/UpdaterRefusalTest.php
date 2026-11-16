<?php

declare(strict_types=1);

use App\Domain\Updates\Requests\UpdateKind;
use App\Domain\Updates\UpdatePlan;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    updSite('0.1.0-rc.14', ['0.1.0-rc.15', '0.1.0-rc.14']);
});

it('refuses an update of Teme while a plugin or theme request is open, and allows it once that has ended', function (string $status): void {
    $id = extRequestRow($status);

    expect(app(UpdatePlan::class)->refusal(UpdateKind::Update, '0.1.0-rc.15'))->toBe('A plugin or theme request is under way. Wait for it to end, or cancel it.');

    DB::table('extension_requests')->where('id', $id)->update(['status' => 'done', 'finished_at' => now()]);

    expect(app(UpdatePlan::class)->refusal(UpdateKind::Update, '0.1.0-rc.15'))->toBeNull();
})->with(['requested', 'running', 'waiting']);
