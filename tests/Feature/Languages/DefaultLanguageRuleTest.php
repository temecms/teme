<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Languages\Languages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    siteSetLanguage($this->admin, 'en-GB');
});

it('refuses a new default language while other languages are added', function (): void {
    Languages::set(['fr']);
    $version = DB::table('settings')->where('group_key', 'general')->value('version');

    expect(fn () => siteSetLanguage($this->admin, 'de'))
        ->toThrow(ValidationException::class, 'The default language can\'t change while the site publishes in other languages. Remove them in Settings › Languages first.')
        ->and(DB::table('settings')->where('group_key', 'general')->value('version'))->toBe($version);
});

it('refuses a default that is already one of the other languages', function (): void {
    Languages::set(['fr']);

    expect(fn () => siteSetLanguage($this->admin, 'fr'))
        ->toThrow(ValidationException::class, 'French is one of the site\'s other languages. Remove it in Settings › Languages before making it the default.');
});

it('changes the default as before on a site with one language', function (): void {
    siteSetLanguage($this->admin, 'de');

    expect(DB::table('settings')->where('group_key', 'general')->value('overrides'))->toContain('"language": "de"');
});
