<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\People\Credit;
use App\Domain\People\Credits;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('editor');
    $this->grace = User::factory()->create(['name' => 'Grace Hopper'])->assignRole('editor');
    app(PathProjection::class)->syncUser($this->ada);
    app(PathProjection::class)->syncUser($this->grace);
    $this->article = addrPublished($this->ada, 'Harbour news');
    $this->run = function (string $command, array $arguments): array {
        $status = Artisan::call($command, $arguments);

        return [$status, trim(Artisan::output())];
    };
    $this->credits = fn (): array => app(Credits::class)->forItems([$this->article->id => (int) $this->article->author_id])[$this->article->id];
});

it('adds a person and prints their address', function (): void {
    [$status, $output] = ($this->run)('teme:people:add', ['name' => 'Reuters', '--slug' => 'reuters', '--bio' => 'News agency']);

    expect($status)->toBe(0)
        ->and($output)->toBe('Added Reuters at /author/reuters/.')
        ->and(DB::table('people')->whereNull('user_id')->first(['name', 'slug', 'bio']))->toEqual((object) ['name' => 'Reuters', 'slug' => 'reuters', 'bio' => 'News agency']);
});

it('sets an item\'s credits by slug, in order, and prints them', function (): void {
    $reuters = Credited::guest('Reuters', 'reuters');

    [$status, $output] = ($this->run)('teme:bylines:set', ['item' => $this->article->id, 'people' => ['reuters', $this->grace->slug]]);

    expect($status)->toBe(0)
        ->and($output)->toBe('“Harbour news” now credits Reuters, Grace Hopper.')
        ->and(($this->credits)())->toEqual([Credit::person($reuters), Credit::account((int) $this->grace->id)]);
});

it('credits the owner again with --owner', function (): void {
    Credited::set($this->article->id, [Credit::person(Credited::guest('Reuters', 'reuters'))]);

    [$status, $output] = ($this->run)('teme:bylines:set', ['item' => $this->article->id, '--owner' => true]);

    expect($status)->toBe(0)
        ->and($output)->toBe('“Harbour news” now credits its owner, Ada Lovelace.')
        ->and(($this->credits)())->toEqual([Credit::account((int) $this->ada->id)])
        ->and(DB::table('content_bylines')->count())->toBe(0);
});

it('refuses an unknown slug before writing anything', function (): void {
    Credited::guest('Reuters', 'reuters');

    [$status, $output] = ($this->run)('teme:bylines:set', ['item' => $this->article->id, 'people' => ['reuters', 'nobody-at-all']]);

    expect($status)->toBe(1)
        ->and($output)->toBe('There\'s no one with the address slug nobody-at-all. Nothing was changed.')
        ->and(DB::table('content_bylines')->count())->toBe(0)
        ->and(DB::table('people')->whereNotNull('user_id')->count())->toBe(0);
});

it('refuses neither people nor --owner', function (): void {
    [$status, $output] = ($this->run)('teme:bylines:set', ['item' => $this->article->id]);

    expect($status)->toBe(1)
        ->and($output)->toBe('Name the people to credit, or pass --owner.')
        ->and(DB::table('content_bylines')->count())->toBe(0);
});

it('touches no model or table in the commands but through Operations and People', function (): void {
    foreach (['AddPerson', 'SetBylinesCommand'] as $command) {
        $source = (string) file_get_contents(app_path("Console/Commands/{$command}.php"));

        expect($source)->not->toContain('DB::')
            ->and($source)->not->toContain('::query(')
            ->and($source)->not->toContain('Models\\');
    }
});
