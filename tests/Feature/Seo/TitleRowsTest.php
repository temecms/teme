<?php

declare(strict_types=1);

use App\Domain\Seo\TitlesAndListing;
use App\Domain\Site\Head\TitleTemplate;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\Setting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->save = fn (array $values) => $this->actingAs($this->admin)->from('/admin/settings/seo')
        ->put('/admin/settings/seo', ['values' => $values, 'expected_version' => 0]);
});

it('gives a row for each kind of page in the section\'s order, each with its template, its default and its hide box, and none for the home page, search results and "page not found" (I-853, I-854)', function (): void {
    $rows = (new TitlesAndListing)->rows();

    expect(array_column($rows, 'key'))->toBe(['type:post', 'type:page', 'home', 'taxonomy:category', 'taxonomy:tag', 'author', 'date', 'search', 'not_found'])
        ->and(array_column($rows, 'label'))->toBe(['Posts', 'Pages', 'Home page', 'Category archives', 'Tag archives', 'Author archives', 'Date archives', 'Search results', 'Page not found'])
        ->and(array_column($rows, 'hide', 'key'))->toMatchArray(['type:post' => 'hide_type_post', 'home' => null, 'taxonomy:tag' => 'hide_taxonomy_tag', 'search' => null, 'not_found' => null])
        ->and(array_column($rows, 'default', 'key'))->toMatchArray(['type:post' => TitlesAndListing::ITEM, 'home' => TitlesAndListing::HOME, 'author' => TitlesAndListing::ARCHIVE, 'not_found' => TitlesAndListing::ITEM]);
});

it('puts every template and hide box of the section in exactly one row, so none goes missing from the screen', function (): void {
    $keys = array_map(fn (Setting $setting): string => $setting->key, (new TitlesAndListing)->settings());
    $placed = [];

    foreach ((new TitlesAndListing)->rows() as $row) {
        $placed[] = $row['template'];

        if ($row['hide'] !== null) {
            $placed[] = $row['hide'];
        }
    }

    expect($placed)->toEqualCanonicalizing(array_values(array_diff($keys, ['title_separator'])))
        ->and(count($placed))->toBe(count(array_unique($placed)));
});

it('refuses a template naming a token Teme hasn\'t got, naming it, and keeps the template as it was', function (string $template, string $token): void {
    ($this->save)(['title_type_post' => $template])
        ->assertSessionHasErrors(['values.title_type_post' => "Teme has no {{$token}} token. Use the tokens listed above."]);

    expect(TitlesAndListing::template('type:post'))->toBe(TitlesAndListing::ITEM);
})->with([
    'a Yoast habit' => ['{title} {sep} {sitename}', 'sitename'],
    'a capital' => ['{Title} {sep} {site}', 'Title'],
]);

it('accepts every token Teme has, and an emptied template, which reads as its default', function (): void {
    ($this->save)([
        'title_type_post' => implode(' ', array_map(fn (string $token): string => "{{$token}}", TitleTemplate::TOKENS)),
        'title_home' => '',
    ])->assertSessionHasNoErrors();

    expect(TitlesAndListing::template('home'))->toBe(TitlesAndListing::HOME);
});
