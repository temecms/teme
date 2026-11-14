<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ReindexSearchInput;
use App\Domain\Contents\Operations\ReindexSearch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Languages\Languages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    siteSetLanguage($this->admin, 'en-GB');
    Languages::set(['fr']);

    $this->english = searchPublished($this->editor, 'Harbour notes', 'The lifeboats were running all night.');
    $this->french = searchPublished($this->editor, 'Le port', 'Les bateaux partaient chaque nuit.');
    DB::table('contents')->where('id', $this->french->id)->update(['language' => 'fr']);
});

it('rebuilds each row with its own language\'s rules', function (): void {
    DB::table('contents')->where('id', $this->french->id)->update(['search_config' => 'english']);
    DB::table('contents')->where('id', $this->english->id)->update(['search_config' => 'french']);

    $result = app(ReindexSearch::class)->execute(new ReindexSearchInput((string) $this->admin->id));

    expect($result)->toBe(['reindexed' => 2, 'remaining' => 0])
        ->and(DB::table('contents')->where('id', $this->french->id)->value('search_config'))->toBe('french')
        ->and(DB::table('contents')->where('id', $this->english->id)->value('search_config'))->toBe('english');
});

it('leaves rows already built with their own language\'s rules alone', function (): void {
    DB::table('contents')->where('id', $this->french->id)->update(['search_config' => 'english']);
    app(ReindexSearch::class)->execute(new ReindexSearchInput((string) $this->admin->id));

    expect(app(ReindexSearch::class)->execute(new ReindexSearchInput((string) $this->admin->id)))->toBe(['reindexed' => 0, 'remaining' => 0]);
});

it('still writes nothing when someone without the ability asks', function (): void {
    DB::table('contents')->where('id', $this->french->id)->update(['search_config' => 'english']);

    expect(fn () => app(ReindexSearch::class)->execute(new ReindexSearchInput((string) $this->editor->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('contents')->where('id', $this->french->id)->value('search_config'))->toBe('english');
});
