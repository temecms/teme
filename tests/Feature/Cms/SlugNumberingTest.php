<?php

declare(strict_types=1);

use App\Domain\Contents\Slugs;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Users\UserSlugs;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
});

function accentedCategory(User $actor): string
{
    return app(CreateTerm::class)->execute(new SaveTermInput('category', 'Café', 'café', null, null, (string) $actor->id))->slug;
}

it('numbers a third category with the same accented slug', function (): void {
    expect(accentedCategory($this->editor))->toBe('café')
        ->and(accentedCategory($this->editor))->toBe('café-2')
        ->and(accentedCategory($this->editor))->toBe('café-3');
});

it('numbers a third content item with the same accented slug', function (): void {
    foreach (['café', 'café-2'] as $slug) {
        DB::table('contents')->where('id', lifecycleDraft($this->editor, 'Café '.$slug)->id)->update(['slug' => $slug]);
    }

    expect(Slugs::unique('post', 'café'))->toBe('café-3');
});

it('numbers a third account with the same name slug', function (): void {
    foreach (['cafe', 'cafe-2'] as $slug) {
        User::factory()->create(['slug' => $slug]);
    }

    expect(UserSlugs::unique('Café'))->toBe('cafe-3');
});
