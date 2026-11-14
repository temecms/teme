<?php

declare(strict_types=1);

use App\Domain\Export\ExportKinds;
use App\Domain\Export\Kinds\BylinesKind;
use App\Domain\People\Credit;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Export\ExportAs;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.org', 'bio' => 'Mathematician'])->assignRole('editor');
    User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.org'])->assignRole('editor');
    $this->reuters = Credited::guest('Reuters', 'reuters', 'News agency');
});

it('exports every person, with an account or without, as pages show them', function (): void {
    $article = addrPublished($this->ada, 'Harbour news');
    Credited::set($article->id, [Credit::person($this->reuters), Credit::account((int) $this->ada->id)]);
    $adasPerson = (string) DB::table('people')->where('user_id', $this->ada->id)->value('id');

    $lines = ExportAs::kindLines(app(BylinesKind::class), pageSize: 1);

    expect(collect($lines)->sortBy('id')->values()->all())->toEqual(collect([
        ['id' => $this->reuters, 'account_id' => null, 'name' => 'Reuters', 'slug' => 'reuters', 'bio' => 'News agency'],
        ['id' => $adasPerson, 'account_id' => (int) $this->ada->id, 'name' => 'Ada Lovelace', 'slug' => $this->ada->slug, 'bio' => 'Mathematician'],
    ])->sortBy('id')->values()->all())
        ->and(json_encode($lines))->not->toContain('@');
});

it('writes bylines before terms and contents', function (): void {
    $names = array_map(fn ($kind): string => $kind->name(), (new ExportKinds)->all());

    expect(array_search('bylines', $names, true))->toBe(array_search('people', $names, true) + 1)
        ->and(array_search('bylines', $names, true))->toBeLessThan(array_search('terms', $names, true))
        ->and(array_search('bylines', $names, true))->toBeLessThan(array_search('contents', $names, true));
});
