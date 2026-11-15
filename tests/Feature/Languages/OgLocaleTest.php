<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Languages\Languages;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

/**
 * The values of one Open Graph property on a page, in order.
 *
 * @return list<string>
 */
function olValues(string $address, string $property): array
{
    $values = [];

    foreach (headDom($address)->query("//head/meta[@property='{$property}']") ?: [] as $meta) {
        /** @var DOMElement $meta */
        $values[] = $meta->getAttribute('content');
    }

    return $values;
}

it('writes og:locale in Open Graph\'s form of the page\'s language', function (): void {
    LanguageSite::build();

    expect(olValues('/fr/bonjour/', 'og:locale'))->toBe(['fr_FR'])
        ->and(olValues('/hello/', 'og:locale'))->toBe(['en_GB']);
});

it('writes og:locale on a single-language site too', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    siteSetLanguage($admin, 'es');
    Languages::set([]);
    LanguageSite::version($admin, 'Hola', null, null, [], publish: true);

    expect(olValues('/hola/', 'og:locale'))->toBe(['es_ES'])
        ->and(olValues('/hola/', 'og:locale:alternate'))->toBe([]);
});

it('writes og:locale:alternate for each other version', function (): void {
    LanguageSite::build();
    Languages::set(['fr', 'es']);

    expect(olValues('/hello/', 'og:locale:alternate'))->toBe(['fr_FR', 'es_ES'])
        ->and(olValues('/fr/bonjour/', 'og:locale:alternate'))->toBe(['en_GB', 'es_ES']);
});
