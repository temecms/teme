<?php

declare(strict_types=1);

use App\Domain\Feeds\FeedsSection;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\SettingType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('adds the Feeds section to Search and social at order 600, with its two settings (I-874)', function (): void {
    $group = app(SettingsRegistry::class)->find('seo');
    $items = $group?->setting('feed_items');
    $content = $group?->setting('feed_content');

    expect($items?->type)->toBe(SettingType::Number)
        ->and($items?->label)->toBe('Items in feeds')
        ->and($items?->description)->toBe('How many of the newest items each feed holds, from 1 to 100.')
        ->and([$items?->default, $items?->min, $items?->max])->toBe([10, 1, 100])
        ->and($content?->type)->toBe(SettingType::Select)
        ->and($content?->label)->toBe('Feeds show')
        ->and($content?->description)->toBe('The full text can be read in a feed reader; the excerpt sends readers to the site to read on.')
        ->and($content?->options)->toBe(['full' => 'The full text', 'excerpt' => 'The excerpt'])
        ->and($content?->default)->toBe('full')
        ->and(array_map(fn (SearchAndSocialSection $section): array => [$section->order(), $section->key(), $section->label()], SearchAndSocial::ordered(app()->tagged(SearchAndSocial::SECTIONS))))
        ->toContain([600, 'feeds', 'Feeds']);
});

it('reads the settings through the reader: ten items and the full text until they are changed', function (): void {
    expect(FeedsSection::items())->toBe(10)
        ->and(FeedsSection::fullText())->toBeTrue();

    cmSettings(['feed_items' => 25, 'feed_content' => 'excerpt'], 'seo');

    expect(FeedsSection::items())->toBe(25)
        ->and(FeedsSection::fullText())->toBeFalse();
});

it('lets an administrator change them on the settings page, and refuses a number out of range or an option that does not exist', function (): void {
    $save = fn (array $values) => $this->actingAs($this->admin)->put('/admin/settings/seo', ['values' => $values, 'expected_version' => 0]);

    $save(['feed_items' => 0])->assertSessionHasErrors('values.feed_items');
    $save(['feed_items' => 101])->assertSessionHasErrors('values.feed_items');
    $save(['feed_content' => 'atom'])->assertSessionHasErrors('values.feed_content');

    expect(FeedsSection::items())->toBe(10);

    $save(['feed_items' => 25, 'feed_content' => 'excerpt'])->assertSessionHasNoErrors()->assertRedirect('/admin/settings/seo');

    expect(FeedsSection::items())->toBe(25)
        ->and(FeedsSection::fullText())->toBeFalse();
});
