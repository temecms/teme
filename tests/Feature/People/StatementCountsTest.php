<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Switches::apiOn();
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('editor');
    app(PathProjection::class)->syncUser($this->ada);
    $this->article = addrPublished($this->ada, 'Harbour news');
    addrPublished($this->ada, 'Second story');
    cmComment($this->article, attributes: ['author_name' => 'Ada Lovelace', 'author_user_id' => $this->ada->id]);

    // Every page once first, so what a process reads once and keeps (whichever test runs first) is counted in none.
    foreach (['/', '/api/v1/items', '/author/'.$this->ada->slug.'/', '/harbour-news/', '/second-story/'] as $page) {
        addrGet($page)->assertOk();
    }

    $this->statements = function (string $page): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        addrGet($page)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
});

// Main at be15bdeb draws these in 10, 9, 3, 14 and 12 statements (I-3366).
it('draws an author page of an account no byline touches in no more statements than main', function (): void {
    expect(($this->statements)('/author/'.$this->ada->slug.'/'))->toBe(9);
});

it('draws the home page and the plain API list in no more statements than main', function (): void {
    expect(($this->statements)('/'))->toBe(8)
        ->and(($this->statements)('/api/v1/items'))->toBe(3);
});

it('marks a credited account\'s comment on an article in no more statements than main', function (): void {
    expect(($this->statements)('/harbour-news/'))->toBe(13)
        ->and(($this->statements)('/second-story/'))->toBe(11);
});
