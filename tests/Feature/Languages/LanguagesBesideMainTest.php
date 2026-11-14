<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Http\Middleware\HoldUntilSetUp;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Languages\Languages;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

/**
 * One row of the path table, written as a later release would.
 */
function besideMainRow(string $path, string $type, string $id, bool $current): void
{
    DB::table('paths')->insert(['path' => $path, 'target_type' => $type, 'target_id' => $id, 'is_current' => $current, 'created_at' => now(), 'replaced_at' => $current ? null : now()]);
}

it('answers an address of a kind it does not know under a prefix with 404, in that language', function (): void {
    $this->seed(RoleSeeder::class);
    LanguageSite::build();
    besideMainRow('fr/gens/jeanne', 'gadget', '01kv7m3q9x2c5r8t1w4y6z0b3d', true);

    addrGet('/fr/gens/jeanne/')->assertNotFound()->assertHeader('Content-Language', 'fr');
});

it('answers an author\'s address under a prefix whose ID is not an account\'s with 404, never another account\'s page', function (): void {
    $this->seed(RoleSeeder::class);
    $site = LanguageSite::build();
    app(PathProjection::class)->syncUser($site->editor);
    // An ID of another form whose leading digits are the editor's, so an integer cast finds them.
    besideMainRow('writers/odd', 'user', substr($site->editor->id.'kv7m3q9x2c5r8t1w4y6z0b3d5f', 0, 26), true);

    addrGet('/fr/writers/odd/')->assertNotFound()->assertHeader('Content-Language', 'fr')->assertDontSee('Eddie Editor');
});

it('answers a language\'s addresses with the holding page while no account exists', function (string $path): void {
    $this->withMiddleware(HoldUntilSetUp::class);
    Languages::set(['fr'], hidden: []);

    expect(User::query()->count())->toBe(0);
    $this->get($path)->assertStatus(503)->assertSee('This site is being set up and will open soon.');
})->with(['/fr/', '/fr/bonjour/', '/fr/feed/']);
