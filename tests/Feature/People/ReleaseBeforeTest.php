<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Addresses\Paths;
use App\Domain\Deployment\BreaksPreviousRelease;
use App\Domain\People\Credit;
use App\Domain\Users\Operations\DeleteUser;
use App\Domain\Users\Operations\ImportUser;
use App\Domain\Users\Operations\Input\DeleteUserInput;
use App\Domain\Users\Operations\Input\ImportUserInput;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\InviteUser;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->migration = database_path('migrations/2027_06_01_100000_create_people_tables.php');
    $this->rows = fn (string $table): array => array_map(fn (object $row): array => (array) $row, DB::table($table)->orderBy(DB::raw('1'))->get()->all());
});

it('writes no row in the update: paths, users and contents are as they were', function (): void {
    $ada = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('editor');
    $grace = User::factory()->create(['name' => 'Grace Hopper'])->assignRole('editor');
    DB::table('paths')->insert([
        ['path' => 'author/ada-lovelace', 'target_type' => 'user', 'target_id' => (string) $ada->id, 'is_current' => true, 'created_at' => now(), 'replaced_at' => null],
        ['path' => 'writers/ada-lovelace', 'target_type' => 'user', 'target_id' => (string) $ada->id, 'is_current' => false, 'created_at' => now(), 'replaced_at' => now()],
        ['path' => 'author/grace-hopper', 'target_type' => 'user', 'target_id' => (string) $grace->id, 'is_current' => true, 'created_at' => now(), 'replaced_at' => null],
    ]);
    lifecycleDraft($ada, 'An article');
    lifecycleDraft($grace, 'A page', 'page');

    // The tables this migration makes are gone, as on the release before.
    Schema::dropIfExists('content_bylines');
    // CASCADE drops only the foreign keys later tables hold to it, which the release before had none of.
    DB::statement('DROP TABLE IF EXISTS people CASCADE');

    $before = ['paths' => ($this->rows)('paths'), 'users' => ($this->rows)('users'), 'contents' => ($this->rows)('contents'), 'terms' => ($this->rows)('terms')];

    (require $this->migration)->up();

    expect(['paths' => ($this->rows)('paths'), 'users' => ($this->rows)('users'), 'contents' => ($this->rows)('contents'), 'terms' => ($this->rows)('terms')])->toBe($before)
        ->and(DB::table('people')->count())->toBe(0)
        ->and(DB::table('content_bylines')->count())->toBe(0);
});

it('makes no migration of this release break the previous one', function (): void {
    $files = glob(database_path('migrations/2027_06_01_*.php')) ?: [];

    expect($files)->not->toBe([]);

    foreach ($files as $file) {
        expect(require $file)->not->toBeInstanceOf(BreaksPreviousRelease::class);
    }
});

it('gives an invited or imported account its author page as the release before reads it', function (): void {
    Notification::fake();
    $this->withElevation();
    $admin = User::factory()->create()->assignRole('administrator');

    app(InviteUser::class)->execute(new InviteUserInput('Ada Lovelace', 'ada@example.com', 'author', (string) $admin->id, deliver: false));
    app(ImportUser::class)->execute(new ImportUserInput('Grace Hopper', 'grace@example.com', 'grace', null, 'author', null, null, (string) $admin->id));
    $ada = User::query()->where('email', 'ada@example.com')->firstOrFail();
    $grace = User::query()->where('email', 'grace@example.com')->firstOrFail();

    expect(Paths::current('user', (string) $ada->id))->toBe('author/'.$ada->slug)
        ->and(Paths::current('user', (string) $grace->id))->toBe('author/grace')
        ->and(DB::table('people')->count())->toBe(0)
        ->and(DB::table('paths')->where('target_type', 'person')->count())->toBe(0);
});

it('writes a person page only for someone with no account', function (): void {
    $ada = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('editor');
    app(PathProjection::class)->syncUser($ada);
    $reuters = Credited::guest('Reuters', 'reuters');
    $article = lifecycleDraft($ada, 'Article');
    Credited::set($article->id, [Credit::person($reuters), Credit::account((int) $ada->id)]);
    $adasPerson = (string) DB::table('people')->where('user_id', $ada->id)->value('id');
    app(PathProjection::class)->syncPerson($reuters);
    app(PathProjection::class)->syncPerson($adasPerson);

    expect(DB::table('paths')->where('target_type', 'person')->pluck('target_id')->all())->toBe([$reuters])
        ->and(Paths::current('user', (string) $ada->id))->toBe('author/'.$ada->slug);
});

it('leaves a row the release before answers with 404', function (): void {
    $this->withElevation();
    $admin = User::factory()->create()->assignRole('administrator');
    $ada = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('author');
    $heir = User::factory()->create(['name' => 'Grace Hopper'])->assignRole('author');
    app(PathProjection::class)->syncUser($ada);
    addrPublished($ada, 'Harbour news');

    app(DeleteUser::class)->execute(new DeleteUserInput((string) $ada->id, (string) $heir->id, (string) $admin->id));
    $row = DB::table('paths')->where('path', 'author/'.$ada->slug)->first(['target_type', 'target_id']);

    // The release before knows no kind person: its guard answers 404, never an account read from the ID.
    expect($row?->target_type)->toBe('person')
        ->and(ctype_digit((string) $row?->target_id))->toBeFalse()
        ->and(DB::table('people')->where('id', $row?->target_id)->whereNull('user_id')->exists())->toBeTrue();
});
